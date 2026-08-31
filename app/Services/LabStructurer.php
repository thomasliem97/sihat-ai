<?php

namespace App\Services;

use App\Enums\Modality;
use App\Models\MedicalRecord;
use App\Support\LabTextExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LabStructurer
{
    public function __construct(private LabTextExtractor $labText) {}

    /**
     * Fill biomarkers/findings from a MedGemma lab draft when GPU skipped JSON.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function mergeIfNeeded(MedicalRecord $record, array $result, ?string $detectedModality = null): array
    {
        if (! $this->needsStructure($record, $result, $detectedModality)) {
            return $result;
        }

        $draft = trim((string) $result['medgemma_draft']);
        $language = $record->language->value;
        $structured = $this->structure($draft, $language, $this->labText->extract($record));
        if ($structured === []) {
            return $result;
        }

        $model = $this->model();

        return array_merge($result, $structured, [
            'medgemma_draft' => $draft,
            'findings_narrative' => $result['findings_narrative'] ?? '',
            'impression' => $result['impression'] ?? '',
            'engine' => 'medgemma+'.$model,
            'structurer' => $model,
            'structured' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function needsStructure(MedicalRecord $record, array $result, ?string $detectedModality = null): bool
    {
        $modality = Modality::tryFrom((string) $detectedModality)
            ?? $record->detected_modality
            ?? $record->modality;

        if ($modality !== Modality::LabPdf) {
            return false;
        }

        if (trim((string) ($result['medgemma_draft'] ?? '')) === '') {
            return false;
        }

        if (($result['structured'] ?? null) === true) {
            return false;
        }

        return ! $this->hasBiomarkers($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function structure(string $draft, string $language = 'en', string $printout = ''): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '' || trim($draft) === '') {
            return [];
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(90)
                ->connectTimeout(10)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $this->model(),
                    'reasoning' => ['effort' => $this->effort()],
                    'instructions' => $this->instructions($language),
                    'input' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'input_text',
                                    'text' => $this->inputText($draft, $printout),
                                ],
                            ],
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'lab_result',
                            'strict' => true,
                            'schema' => $this->schema(),
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Lab structurer request failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Lab structurer HTTP error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 400),
            ]);

            return [];
        }

        $text = trim((string) $response->json('output_text'));
        if ($text === '') {
            $chunks = [];
            foreach ($response->json('output') ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ($item['content'] ?? [] as $part) {
                    if (is_array($part) && is_string($part['text'] ?? null)) {
                        $chunks[] = $part['text'];
                    }
                }
            }
            $text = trim(implode('', $chunks));
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            Log::warning('Lab structurer returned non-object JSON');

            return [];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function hasBiomarkers(array $result): bool
    {
        $rows = $result['biomarkers'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return false;
        }

        foreach ($rows as $row) {
            if (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function model(): string
    {
        return (string) (config('services.openai.structure_model') ?: 'gpt-5.6-terra');
    }

    private function effort(): string
    {
        return (string) (config('services.openai.lab_structure_effort') ?: 'low');
    }

    private function instructions(string $language): string
    {
        $audience = match (true) {
            str_starts_with(strtolower($language), 'ms'), in_array(strtolower($language), ['bm', 'malay'], true) => 'Bahasa Melayu',
            str_starts_with(strtolower($language), 'zh'), in_array(strtolower($language), ['cn', 'mandarin', 'chinese'], true) => 'Mandarin Chinese',
            str_starts_with(strtolower($language), 'ta'), strtolower($language) === 'tamil' => 'Tamil',
            default => 'English',
        };

        return <<<TXT
Convert the lab printout and MedGemma interpretive draft into JSON matching the schema.
Input: PRINTOUT TEXT (source of numbers) plus an interpretive draft.
Output: JSON matching the schema with findings + biomarkers.
Write patient_report and recommendations in {$audience}.
biomarkers: every numeric analyte that appears in the PRINTOUT; ignore analytes the draft invented.
status from a printed flag (H, L, critical) or by comparing the result to the printed interval (normal|borderline|abnormal|critical).
findings: only out-of-range or lab-flagged analytes; description must be a full sentence (how far from the interval, and a caution). A printed H/L/* flag is not normal. If none are abnormal, findings may be [].
recommendations: at most three next steps from IMPRESSION. Do not copy a shotgun consider-checking list. Do not recommend tests already printed on this report.
If the panel is in range, one recommendation that no lab-based caution is indicated.
differential_diagnosis: pattern hypotheses from IMPRESSION only; [] if none. Do not invent DDx from a single non-specific marker such as ESR.
patient_report from the draft: abnormal results, what they may mean, questions_for_doctor, action_plan. No definitive diagnosis as fact.
Use exact numeric values from the printout. For censored results like >60 or <0.1, put the number only in value/reference_low/reference_high.
Missing fields use empty strings. bounding_boxes optional; else [].
TXT;
    }

    private function inputText(string $draft, string $printout): string
    {
        $draft = trim($draft);
        $printout = trim($printout);
        if ($printout === '') {
            return "MedGemma lab draft:\n\n".$draft;
        }

        return "PRINTOUT TEXT (extract numbers only from here; omit analytes that do not appear):\n\n"
            .mb_substr($printout, 0, 12000)
            ."\n\nINTERPRETIVE DRAFT:\n\n".$draft;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $severity = ['type' => 'string', 'enum' => ['normal', 'borderline', 'abnormal', 'critical']];
        $string = ['type' => 'string'];
        $number = ['type' => 'number'];
        $stringList = ['type' => 'array', 'items' => $string];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'label' => $string,
                            'value' => $string,
                            'unit' => $string,
                            'reference' => $string,
                            'severity' => $severity,
                            'confidence' => $number,
                            'description' => $string,
                        ],
                        'required' => ['label', 'value', 'unit', 'reference', 'severity', 'confidence', 'description'],
                    ],
                ],
                'biomarkers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => $string,
                            'value' => $string,
                            'unit' => $string,
                            'reference_low' => $string,
                            'reference_high' => $string,
                            'status' => $severity,
                        ],
                        'required' => ['name', 'value', 'unit', 'reference_low', 'reference_high', 'status'],
                    ],
                ],
                'differential_diagnosis' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'condition' => $string,
                            'confidence' => $number,
                        ],
                        'required' => ['condition', 'confidence'],
                    ],
                ],
                'overall_confidence' => $number,
                'recommendations' => $stringList,
                'patient_report' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'summary' => $string,
                        'what_this_means' => $string,
                        'questions_for_doctor' => $stringList,
                        'action_plan' => $stringList,
                    ],
                    'required' => ['summary', 'what_this_means', 'questions_for_doctor', 'action_plan'],
                ],
                'bounding_boxes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'label' => $string,
                            'x' => $number,
                            'y' => $number,
                            'width' => $number,
                            'height' => $number,
                            'confidence' => $number,
                        ],
                        'required' => ['label', 'x', 'y', 'width', 'height', 'confidence'],
                    ],
                ],
            ],
            'required' => [
                'findings',
                'biomarkers',
                'differential_diagnosis',
                'overall_confidence',
                'recommendations',
                'patient_report',
                'bounding_boxes',
            ],
        ];
    }
}
