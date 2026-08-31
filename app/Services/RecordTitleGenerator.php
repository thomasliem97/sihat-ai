<?php

namespace App\Services;

use App\Models\MedicalRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecordTitleGenerator
{
    public static function fromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\s_]+/u', ' ', $base));
        $base = trim($base, ' .-');

        if ($base === '') {
            return 'Untitled record';
        }

        return mb_substr($base, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function suggest(MedicalRecord $record, array $result): ?string
    {
        if (! $record->title_generated) {
            return null;
        }

        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->connectTimeout(10)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => (string) (config('services.openai.structure_model') ?: 'gpt-5.6-terra'),
                    'reasoning' => ['effort' => (string) (config('services.openai.lab_structure_effort') ?: 'low')],
                    'instructions' => $this->instructions(),
                    'input' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'input_text',
                                    'text' => $this->inputText($record, $result),
                                ],
                            ],
                        ],
                    ],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'record_title',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                ],
                                'required' => ['title'],
                            ],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Record title generation failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Record title generation HTTP error', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $decoded = $this->decode($response->json());
        $title = trim((string) ($decoded['title'] ?? ''));
        $title = trim($title, " \"'");

        if ($title === '' || mb_strlen($title) < 3) {
            return null;
        }

        return mb_substr($title, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function inputText(MedicalRecord $record, array $result): string
    {
        $modality = ($record->detected_modality ?? $record->modality)->label();
        $labels = collect($result['findings'] ?? [])
            ->map(fn (mixed $row): string => is_array($row) ? trim((string) ($row['label'] ?? '')) : '')
            ->filter()
            ->take(8)
            ->implode('; ');
        $impression = trim((string) (
            $result['impression']
            ?? data_get($result, 'physician_report.impression')
            ?? ''
        ));
        $draft = trim((string) ($result['medgemma_draft'] ?? data_get($result, 'physician_report.medgemma_draft') ?? ''));

        return implode("\n", array_filter([
            'Modality: '.$modality,
            'Filename: '.$record->original_filename,
            $labels !== '' ? 'Findings: '.$labels : null,
            $impression !== '' ? 'Impression: '.mb_substr($impression, 0, 400) : null,
            $draft !== '' ? 'Draft: '.mb_substr($draft, 0, 400) : null,
        ]));
    }

    private function instructions(): string
    {
        return <<<'TXT'
Write a short clinic-list title for this medical record, 6 to 12 words, sentence case.
Name the study type and the main finding or reason for the test. No patient name, no dates, no IDs.
Do not state a diagnosis as fact. Do not wrap the title in quotes.
TXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        $text = trim((string) ($json['output_text'] ?? ''));
        if ($text === '') {
            $chunks = [];
            foreach ($json['output'] ?? [] as $item) {
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

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
