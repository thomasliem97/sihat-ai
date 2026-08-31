<?php

namespace App\Services;

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Jobs\FailStaleAnalysis;
use App\Jobs\ProcessMedicalRecord;
use App\Models\AnalysisJob;
use App\Models\AuditEvent;
use App\Models\Biomarker;
use App\Models\MedicalRecord;
use App\Notifications\CriticalEscalationNotification;
use App\Support\LabTextExtractor;
use App\Support\MedgemmaDraft;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiPipelineService
{
    public function __construct(
        private DeidentificationService $deidentification,
        private RagService $rag,
        private SimilarCaseService $similarCases,
        private LabTextExtractor $labText,
        private LabStructurer $labStructurer,
        private RecordTitleGenerator $titles,
    ) {}

    public function dispatch(MedicalRecord $record): AnalysisJob
    {
        $record->update([
            'status' => RecordStatus::Processing,
            'pipeline_steps' => $this->formatPipelineSteps(
                ['upload'],
                running: 'deidentify',
            ),
        ]);

        $job = AnalysisJob::create([
            'medical_record_id' => $record->id,
            'status' => 'pending',
            'external_job_id' => (string) Str::uuid(),
            'started_at' => now(),
        ]);

        ProcessMedicalRecord::dispatch($record, $job);

        return $job;
    }

    public function retry(MedicalRecord $record): AnalysisJob
    {
        $record->update([
            'error_message' => null,
        ]);

        return $this->dispatch($record);
    }

    /**
     * De-identify, route, and hand off to FastAPI. Record stays processing until webhook.
     */
    public function beginRemoteAnalysis(MedicalRecord $record, AnalysisJob $job): void
    {
        $this->prepareRecord($record, $job);
        $job->refresh();

        $modality = $record->detected_modality ?? $record->modality;
        $baseUrl = rtrim((string) config('services.modal.url'), '/');
        $webhookUrl = rtrim((string) config('app.url'), '/').'/api/ai/webhook';
        $path = $record->inferenceFilePath();
        if (! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('Inference file missing for analyze handoff: '.$path);
        }

        $analyzeStartedAt = microtime(true);

        $payload = [
            'job_id' => $job->external_job_id,
            'record_id' => $record->id,
            'modality' => $modality->value,
            'file_path' => $path,
            'language' => $record->language->value,
            'webhook_url' => $webhookUrl,
            'mime_type' => $record->mime_type,
            'original_filename' => $record->original_filename,
            'route_confidence' => $record->route_confidence,
            'engine' => 'medgemma',
            'adapter' => config('services.modal.lora_path') ? 'configured' : 'none',
        ];

        $labText = $modality === Modality::LabPdf ? $this->labText->extract($record) : '';
        if ($labText !== '') {
            $payload['lab_text'] = mb_substr($labText, 0, 12000);
        } else {
            $bytes = Storage::disk('local')->get($path);
            if ($bytes === null || $bytes === '') {
                throw new \RuntimeException('Inference file missing for analyze handoff: '.$path);
            }
            // Modal cannot pull trycloudflare/ngrok signed URLs (datacenter IP blocked).
            $payload['file_b64'] = base64_encode($bytes);
        }

        $response = Http::connectTimeout(15)
            ->timeout(120)
            ->retry(2, 100)
            ->post("{$baseUrl}/api/v1/analyze", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'AI service rejected analyze request: HTTP '.$response->status()
            );
        }

        $timings = $job->hop_timings ?? [];
        $timings['analyze_started_at'] = $analyzeStartedAt;

        $job->update([
            'status' => 'running',
            'steps_completed' => ['upload', 'deidentify', 'route', 'analyze'],
            'hop_timings' => $timings,
        ]);

        $record->update([
            'pipeline_steps' => $this->formatPipelineSteps(
                ['upload', 'deidentify', 'route', 'analyze'],
                running: 'analyze',
            ),
        ]);

        FailStaleAnalysis::dispatch($job->external_job_id)
            ->delay(now()->addMinutes(FailStaleAnalysis::STALE_AFTER_MINUTES));
    }

    /**
     * Complete analysis after FastAPI webhook delivers raw findings.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function completeFromWebhook(MedicalRecord $record, AnalysisJob $job, array $result): array
    {
        $result = $this->structureLabResultIfNeeded($record, $result);

        return $this->finalizeResult($record, $job, $result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function structureLabResultIfNeeded(MedicalRecord $record, array $result, ?string $detectedModality = null): array
    {
        return $this->labStructurer->mergeIfNeeded($record, $result, $detectedModality);
    }

    /**
     * Persist completed analysis onto the record and job.
     *
     * @param  array<string, mixed>  $result
     */
    public function persistCompleted(MedicalRecord $record, AnalysisJob $job, array $result): void
    {
        $embedding = $this->similarCases->embedResult($record, $result);
        $generatedTitle = $this->titles->suggest($record, $result);

        $record->update([
            'status' => RecordStatus::Completed,
            ...($generatedTitle !== null ? ['title' => $generatedTitle] : []),
            'detected_modality' => $result['detected_modality'] ?? $record->detected_modality,
            'route_confidence' => $result['route_confidence'] ?? $record->route_confidence,
            'findings' => $result['findings'] ?? null,
            'partial_findings' => $result['partial_findings'] ?? null,
            'physician_report' => $result['physician_report'] ?? null,
            'patient_report' => $result['patient_report'] ?? null,
            'citations' => $result['citations'] ?? null,
            'bounding_boxes' => $result['bounding_boxes'] ?? null,
            'longitudinal_diff' => $result['longitudinal_diff'] ?? null,
            'guardrail_flags' => $result['guardrail_flags'] ?? null,
            'pipeline_steps' => $result['pipeline_steps'] ?? null,
            'agent_trace' => $result['agent_trace'] ?? null,
            'findings_embedding' => $embedding !== [] ? $embedding : null,
            'volume_meta' => $result['volume_meta'] ?? null,
            'patch_meta' => $result['patch_meta'] ?? null,
            'overall_confidence' => $result['overall_confidence'] ?? null,
            'analyzed_at' => now(),
            'error_message' => null,
        ]);

        $job->update([
            'status' => 'completed',
            'steps_completed' => ['upload', 'deidentify', 'route', 'analyze', 'rag', 'guardrail', 'compose'],
            'completed_at' => now(),
        ]);
    }

    private function prepareRecord(MedicalRecord $record, AnalysisJob $job): void
    {
        $job->update(['steps_completed' => ['upload'], 'status' => 'running']);
        $timings = $job->hop_timings ?? [];
        $record->update([
            'pipeline_steps' => $this->formatPipelineSteps(
                ['upload'],
                running: 'deidentify',
            ),
        ]);

        $t0 = microtime(true);
        $this->deidentification->deidentify($record);
        $record->refresh();
        $timings['deidentify'] = [
            'duration_ms' => $this->elapsedMs($t0),
            'status' => 'completed',
            'detail' => $record->safe_file_path
                ? 'Created a de-identified copy for analysis.'
                : 'Patient identifiers were removed.',
        ];
        $record->update([
            'deidentified_at' => now(),
            'pipeline_steps' => $this->formatPipelineSteps(
                ['upload', 'deidentify'],
                running: 'route',
            ),
        ]);
        $job->update([
            'steps_completed' => ['upload', 'deidentify'],
            'hop_timings' => $timings,
        ]);

        $t0 = microtime(true);
        $routed = $this->detectModality($record);
        $timings['router'] = [
            'duration_ms' => $this->elapsedMs($t0),
            'status' => 'completed',
            'detail' => 'Detected study type: '.$routed['modality']->label(),
            'confidence' => $routed['confidence'],
        ];
        $record->update([
            'detected_modality' => $routed['modality'],
            'route_confidence' => $routed['confidence'],
            'pipeline_steps' => $this->formatPipelineSteps(
                ['upload', 'deidentify', 'route'],
                running: 'analyze',
            ),
        ]);
        $job->update([
            'steps_completed' => ['upload', 'deidentify', 'route'],
            'hop_timings' => $timings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finalizeResult(MedicalRecord $record, AnalysisJob $job, array $result): array
    {
        $trace = [];
        $timings = $job->hop_timings ?? [];
        $modalityEnum = $record->detected_modality ?? $record->modality;
        $modality = $modalityEnum->value;
        $result['engine'] = $result['engine'] ?? 'medgemma';
        $result['adapter'] = $result['adapter'] ?? (config('services.modal.lora_path') ? 'configured' : 'none');
        if (isset($result['biomarkers']) && is_array($result['biomarkers'])) {
            $result['biomarkers'] = Biomarker::normalizeIncoming($result['biomarkers']);
        }
        $result['findings'] = $this->sanitizeImagingFindings($modalityEnum, $result);
        if ($this->imagingFindingsNeedReview($modalityEnum, $result['findings'])) {
            $result['overall_confidence'] = min((float) ($result['overall_confidence'] ?? 1), 0.35);
        }
        $result['differential_diagnosis'] = $this->sanitizeDifferential(
            $result['differential_diagnosis'] ?? []
        );

        $routerTiming = is_array($timings['router'] ?? null) ? $timings['router'] : [];
        $trace[] = $this->hop(
            'router',
            (string) ($routerTiming['status'] ?? 'completed'),
            (string) ($routerTiming['detail'] ?? 'Detected study type: '.$modalityEnum->label()),
            isset($routerTiming['confidence']) ? (float) $routerTiming['confidence'] : $record->route_confidence,
            durationMs: isset($routerTiming['duration_ms']) ? (int) $routerTiming['duration_ms'] : null,
        );

        $deidentifyTiming = is_array($timings['deidentify'] ?? null) ? $timings['deidentify'] : [];
        if ($record->safe_file_path || $deidentifyTiming !== []) {
            $trace[] = $this->hop(
                'deidentify',
                (string) ($deidentifyTiming['status'] ?? 'completed'),
                (string) ($deidentifyTiming['detail'] ?? 'Created a de-identified copy for analysis.'),
                durationMs: isset($deidentifyTiming['duration_ms']) ? (int) $deidentifyTiming['duration_ms'] : null,
            );
        }

        $analyzeMs = isset($timings['analyze_started_at'])
            ? $this->elapsedMs((float) $timings['analyze_started_at'])
            : null;

        $t0 = microtime(true);
        $partial = $this->buildPartialFindings($modalityEnum, $result);
        $result['partial_findings'] = $partial;
        $imagingActive = $partial['imaging'] !== null;
        $docActive = $partial['document'] !== null;
        $imagingCount = count($partial['imaging']['findings'] ?? []);
        $docCount = count($partial['document']['findings'] ?? []);
        $trace[] = $this->hop(
            'imaging_specialist',
            $imagingActive ? 'completed' : 'skipped',
            $imagingActive
                ? ($imagingCount === 1
                    ? 'Found 1 imaging finding.'
                    : "Found {$imagingCount} imaging findings.")
                : 'Skipped for '.$modalityEnum->label().' studies.',
            $partial['imaging']['overall_confidence'] ?? null,
            durationMs: $imagingActive ? $analyzeMs : null,
        );
        $trace[] = $this->hop(
            'doc_specialist',
            $docActive ? 'completed' : 'skipped',
            $docActive
                ? ($docCount === 1
                    ? 'Found 1 document finding.'
                    : "Found {$docCount} document findings.")
                : 'Skipped for '.$modalityEnum->label().' studies.',
            $partial['document']['overall_confidence'] ?? null,
            durationMs: $docActive ? $analyzeMs : null,
        );
        $result['findings'] = $this->mergePartialFindings($partial, $result['findings']);
        $trace[] = $this->hop(
            'merge',
            'completed',
            'Combined specialist findings into one result set.',
            $result['overall_confidence'] ?? null,
            $t0,
        );

        $t0 = microtime(true);
        $ddxTerms = collect($result['differential_diagnosis'])
            ->map(fn (array $row): string => (string) ($row['condition'] ?? ''))
            ->filter()
            ->all();
        $citations = $this->rag->retrieveCitations($record, $result['findings'], $ddxTerms);
        $result['citations'] = $citations;
        $result['rag_weak'] = $this->rag->wasWeakRetrieval($citations);
        $citationCount = count($citations);
        $trace[] = $this->hop(
            'rag',
            'completed',
            $citationCount === 0
                ? 'No matching guideline citations were found.'
                : ($citationCount === 1
                    ? 'Retrieved 1 supporting guideline citation.'
                    : "Retrieved {$citationCount} supporting guideline citations."),
            null,
            $t0,
        );
        $job->update(['steps_completed' => ['upload', 'deidentify', 'route', 'analyze', 'rag']]);

        $t0 = microtime(true);
        $guardrails = $this->applyGuardrails($result);
        $result['guardrail_flags'] = $guardrails;
        $trace[] = $this->hop(
            'guardrail',
            'completed',
            $this->formatGuardrailHopDetail($guardrails),
            null,
            $t0,
        );
        $job->update(['steps_completed' => ['upload', 'deidentify', 'route', 'analyze', 'rag', 'guardrail']]);

        $this->handleCriticalEscalation($record, $guardrails);

        $t0 = microtime(true);
        if (empty($result['longitudinal_diff'])) {
            $result['longitudinal_diff'] = $this->buildLongitudinalDiff($record, $result);
        }
        $reports = $this->composeReports($record, $result, $guardrails);
        $languageLabel = match ($record->language) {
            ReportLanguage::English => 'English',
            ReportLanguage::Malay => 'Bahasa Melayu',
            ReportLanguage::Mandarin => 'Mandarin',
            ReportLanguage::Tamil => 'Tamil',
        };
        $trace[] = $this->hop(
            'compose',
            'completed',
            'Wrote physician and patient reports in '.$languageLabel.'.',
            null,
            $t0,
        );
        $job->update(['steps_completed' => ['upload', 'deidentify', 'route', 'analyze', 'rag', 'guardrail', 'compose']]);

        return array_merge($result, $reports, [
            'detected_modality' => $modality,
            'route_confidence' => $record->route_confidence,
            'agent_trace' => $trace,
            'pipeline_steps' => $this->formatPipelineSteps([
                'upload', 'deidentify', 'route', 'analyze', 'rag', 'guardrail', 'compose',
            ]),
        ]);
    }

    /**
     * Drop punctuation-only / empty labels.
     * When imaging has no usable findings, force a review item instead of inventing "normal".
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeImagingFindings(Modality $modality, array $result): array
    {
        $findings = [];
        foreach ($result['findings'] ?? [] as $finding) {
            if (! is_array($finding) || ! $this->isUsableClinicalLabel($finding['label'] ?? null)) {
                continue;
            }
            $findings[] = $finding;
        }

        if ($findings !== [] || ! $modality->isImaging()) {
            return $findings;
        }

        return [[
            'label' => 'Unusable model output',
            'description' => 'The model did not return clinically usable findings. Manual review of the source image is required.',
            'severity' => 'borderline',
            'confidence' => 0.2,
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function imagingFindingsNeedReview(Modality $modality, array $findings): bool
    {
        if (! $modality->isImaging()) {
            return false;
        }

        if ($findings === []) {
            return true;
        }

        return count($findings) === 1
            && ($findings[0]['label'] ?? null) === 'Unusable model output';
    }

    /**
     * @param  array<int, mixed>  $differential
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeDifferential(array $differential): array
    {
        $clean = [];
        foreach ($differential as $row) {
            if (! is_array($row) || ! $this->isUsableClinicalLabel($row['condition'] ?? null)) {
                continue;
            }
            $clean[] = $row;
        }

        return $clean;
    }

    private function isUsableClinicalLabel(mixed $label): bool
    {
        if (! is_string($label)) {
            return false;
        }

        $trimmed = trim($label);
        if (mb_strlen($trimmed) < 3) {
            return false;
        }

        return preg_match('/^[\p{P}\p{S}\s]+$/u', $trimmed) !== 1;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{imaging: ?array<string, mixed>, document: ?array<string, mixed>}
     */
    private function buildPartialFindings(Modality $modality, array $result): array
    {
        $bundle = [
            'findings' => $result['findings'] ?? [],
            'overall_confidence' => $result['overall_confidence'] ?? null,
            'biomarkers' => $result['biomarkers'] ?? null,
            'bounding_boxes' => $result['bounding_boxes'] ?? null,
        ];

        if ($modality->isDocument()) {
            return ['imaging' => null, 'document' => $bundle];
        }

        if ($modality->isImaging()) {
            return ['imaging' => $bundle, 'document' => null];
        }

        return ['imaging' => $bundle, 'document' => null];
    }

    /**
     * @param  array{imaging: ?array<string, mixed>, document: ?array<string, mixed>}  $partial
     * @param  array<int, array<string, mixed>>  $fallback
     * @return array<int, array<string, mixed>>
     */
    private function mergePartialFindings(array $partial, array $fallback): array
    {
        $merged = [];
        foreach (['imaging', 'document'] as $key) {
            $findings = $partial[$key]['findings'] ?? null;
            if (is_array($findings)) {
                foreach ($findings as $finding) {
                    $merged[] = is_array($finding)
                        ? array_merge($finding, ['specialist' => $key])
                        : $finding;
                }
            }
        }

        return $merged !== [] ? $merged : $fallback;
    }

    /**
     * @param  array{code: string, flags: list<string>}  $guardrails
     */
    private function handleCriticalEscalation(MedicalRecord $record, array $guardrails): void
    {
        if (! in_array('critical_value_escalation', $guardrails['flags'], true)) {
            return;
        }

        $alreadyEscalated = AuditEvent::query()
            ->where('medical_record_id', $record->id)
            ->where('event', 'critical_value_escalation')
            ->exists();

        if ($alreadyEscalated) {
            return;
        }

        AuditEvent::create([
            'actor_type' => 'system',
            'actor_id' => null,
            'event' => 'critical_value_escalation',
            'medical_record_id' => $record->id,
            'payload' => [
                'guardrail_code' => $guardrails['code'],
                'flags' => $guardrails['flags'],
                'title' => $record->title,
            ],
        ]);

        Log::warning('Critical escalation', [
            'medical_record_id' => $record->id,
            'flags' => $guardrails['flags'],
        ]);

        $physician = $record->uploadedBy;
        if ($physician && $physician->isPhysician()) {
            Notification::send($physician, new CriticalEscalationNotification($record));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function hop(
        string $name,
        string $status,
        string $detail,
        ?float $confidence = null,
        ?float $startedAt = null,
        ?int $durationMs = null,
    ): array {
        $resolvedMs = $durationMs;
        if ($resolvedMs === null && $startedAt !== null) {
            $resolvedMs = $this->elapsedMs($startedAt);
        }

        return [
            'hop' => $name,
            'status' => $status,
            'detail' => $detail,
            'confidence' => $confidence,
            'duration_ms' => $status === 'skipped' ? null : $resolvedMs,
            'ended_at' => now()->toIso8601String(),
        ];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param  array{code?: string, flags?: list<string>}  $guardrails
     */
    public function formatGuardrailHopDetail(array $guardrails): string
    {
        $code = strtoupper((string) ($guardrails['code'] ?? 'ALLOW'));
        $codeLabel = match ($code) {
            'WARN' => 'Proceed with caution',
            default => 'Allowed to proceed',
        };

        $flagLabels = [
            'medical_disclaimer_required' => 'Medical disclaimer required',
            'not_a_diagnosis' => 'Not a diagnosis',
            'confidence_publish' => 'Confidence high enough to publish',
            'confidence_hedge' => 'Mid confidence; patient language softened',
            'low_confidence_abstention' => 'Low confidence; patient report withheld',
            'critical_value_escalation' => 'Critical finding; escalate and withhold patient copy',
            'weak_guideline_grounding' => 'Guideline grounding was weak',
        ];

        $flags = [];
        foreach ($guardrails['flags'] ?? [] as $flag) {
            if ($flag === '') {
                continue;
            }
            $flags[] = $flagLabels[$flag] ?? str_replace('_', ' ', $flag);
        }

        return $flags === []
            ? $codeLabel.'.'
            : $codeLabel.'. '.implode('; ', $flags).'.';
    }

    public function formatTechnicalNotes(
        string $engine,
        string $adapter,
        string $modalityLabel,
        string $guardrailCode,
        bool $adapterUsed = true,
    ): string {
        $engineLabel = match (true) {
            str_contains($engine, '+') => 'MedGemma + secondary LLM',
            str_contains(strtolower($engine), 'medgemma') => 'MedGemma',
            default => $engine,
        };

        $adapterLabel = match (true) {
            $adapter === 'none', $adapter === '' => 'none',
            str_starts_with($adapter, 'loaded:') => 'LoRA (loaded)',
            $adapter === 'configured' => 'LoRA (configured)',
            default => 'LoRA',
        };

        return sprintf(
            'Engine: %s. Adapter: %s. Modality: %s. Guardrail: %s (heuristic). Retrieval: hybrid RAG.',
            $engineLabel,
            $adapterUsed ? $adapterLabel : 'not used on this report',
            $modalityLabel,
            strtoupper($guardrailCode),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    public function buildLongitudinalDiff(MedicalRecord $record, array $result): ?array
    {
        $empty = [
            'has_prior' => false,
            'summary' => 'No patient previous history is found.',
            'changes' => [],
        ];

        if ($record->subject_user_id === null) {
            return $empty;
        }

        $modality = $record->detected_modality ?? $record->modality;

        $prior = MedicalRecord::query()
            ->where('subject_user_id', $record->subject_user_id)
            ->where('id', '!=', $record->id)
            ->where('status', RecordStatus::Completed)
            ->where(function ($q) use ($modality) {
                $q->where('detected_modality', $modality)
                    ->orWhere('modality', $modality);
            })
            ->latest('analyzed_at')
            ->first();

        if (! $prior) {
            return $empty;
        }

        if ($modality === Modality::LabPdf || $modality === Modality::ClinicalDocument) {
            return $this->diffLabRecords($prior, $result);
        }

        return $this->diffImagingFindings($prior, $result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function diffImagingFindings(MedicalRecord $prior, array $result): array
    {
        $priorFindings = $prior->findings ?? [];
        $currentFindings = is_array($result['findings'] ?? null) ? array_values($result['findings']) : [];
        $priorLabels = collect($priorFindings)->pluck('label')->filter()->map(fn ($l) => mb_strtolower((string) $l));
        $currentLabels = collect($currentFindings)->pluck('label')->filter()->map(fn ($l) => mb_strtolower((string) $l));

        $changes = [];
        foreach ($currentLabels as $label) {
            if (! $priorLabels->contains($label)) {
                $changes[] = ['finding' => $label, 'change' => 'new', 'prior_date' => $prior->analyzed_at?->toDateString()];
            } else {
                $changes[] = ['finding' => $label, 'change' => 'stable', 'prior_date' => $prior->analyzed_at?->toDateString()];
            }
        }
        foreach ($priorLabels as $label) {
            if (! $currentLabels->contains($label)) {
                $changes[] = ['finding' => $label, 'change' => 'resolved', 'prior_date' => $prior->analyzed_at?->toDateString()];
            }
        }

        $newCount = collect($changes)->where('change', 'new')->count();
        $summary = $newCount > 0
            ? "Compared to prior study on {$prior->analyzed_at?->toDateString()}: {$newCount} new finding(s)."
            : "Compared to prior study on {$prior->analyzed_at?->toDateString()}: findings largely stable.";

        return [
            'has_prior' => true,
            'summary' => $summary,
            'prior_record_id' => $prior->id,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function diffLabRecords(MedicalRecord $prior, array $result): array
    {
        $priorMarkers = $prior->biomarkers()->get()->keyBy(fn ($b) => mb_strtolower($b->name));
        $biomarkers = is_array($result['biomarkers'] ?? null) ? array_values($result['biomarkers']) : [];
        $current = collect($biomarkers);
        $changes = [];

        foreach ($current as $marker) {
            $name = mb_strtolower((string) ($marker['name'] ?? ''));
            $value = (float) ($marker['value'] ?? 0);
            $old = $priorMarkers->get($name);
            if (! $old) {
                $changes[] = ['finding' => $marker['name'] ?? $name, 'change' => 'new', 'prior_date' => $prior->analyzed_at?->toDateString()];

                continue;
            }
            $delta = $value - (float) $old->value;
            $change = abs($delta) < 0.05 * max(abs((float) $old->value), 1) ? 'stable' : ($delta > 0 ? 'worse' : 'improved');
            $changes[] = [
                'finding' => $marker['name'] ?? $name,
                'change' => $change,
                'prior_value' => $old->value,
                'current_value' => $value,
                'prior_date' => $prior->analyzed_at?->toDateString(),
            ];
        }

        return [
            'has_prior' => true,
            'summary' => 'Lab values compared to prior report on '.$prior->analyzed_at?->toDateString().'.',
            'prior_record_id' => $prior->id,
            'changes' => $changes,
        ];
    }

    /**
     * @return array{modality: Modality, confidence: float}
     */
    public function detectModality(MedicalRecord $record): array
    {
        if ($record->modality !== Modality::Unknown) {
            return ['modality' => $record->modality, 'confidence' => 1.0];
        }

        $mime = strtolower($record->mime_type);
        $filename = strtolower($record->original_filename);

        if (str_contains($mime, 'pdf') || str_ends_with($filename, '.pdf')) {
            if ($this->filenameContainsAny($filename, [
                'discharge', 'summary', 'clinic', 'consult', 'progress', 'note', 'referral', 'letter',
            ])) {
                return ['modality' => Modality::ClinicalDocument, 'confidence' => 0.9];
            }

            return ['modality' => Modality::LabPdf, 'confidence' => 0.95];
        }

        if (str_ends_with($filename, '.dcm') || str_contains($mime, 'dicom')) {
            $fromFile = $this->modalityFromDicomFile($record);
            if ($fromFile !== null) {
                return ['modality' => $fromFile, 'confidence' => 0.9];
            }

            return ['modality' => $this->modalityFromDicomHint($filename), 'confidence' => 0.85];
        }

        $fromName = $this->modalityFromFilenameHints($filename);
        if ($fromName !== null) {
            return $fromName;
        }

        if (str_contains($mime, 'image')) {
            return ['modality' => Modality::Xray, 'confidence' => 0.55];
        }

        return ['modality' => Modality::Unknown, 'confidence' => 0.3];
    }

    /**
     * Filename / zip modality routing shared with FastAPI.
     *
     * @return array{modality: Modality, confidence: float}|null
     */
    private function modalityFromFilenameHints(string $filename): ?array
    {
        if ($this->filenameContainsAny($filename, ['fundus', 'retina', 'ophthal', 'cataract', 'glaucoma', 'eyepacs'])
            || $this->filenameHasToken($filename, 'oct')) {
            return ['modality' => Modality::Ophthalmology, 'confidence' => 0.85];
        }

        if ($this->filenameContainsAny($filename, ['derm', 'skin', 'lesion', 'melanoma', 'nevus', 'isic', 'dermos'])) {
            return ['modality' => Modality::Dermatology, 'confidence' => 0.85];
        }

        if ($this->filenameContainsAny($filename, ['histo', 'pathology', 'pathmnist', 'wsi', 'slide', 'biopsy', 'seminoma', 'pcam'])) {
            return ['modality' => Modality::Histopath, 'confidence' => 0.85];
        }

        if ($this->filenameContainsAny($filename, ['hrct', 'computed tomography', 'computed_tomography'])
            || $this->filenameHasToken($filename, 'ct')) {
            return ['modality' => Modality::Ct, 'confidence' => 0.85];
        }

        if (str_contains($filename, 'mri') || str_contains($filename, 'mr_')) {
            return ['modality' => Modality::Mri, 'confidence' => 0.85];
        }

        if ($this->filenameContainsAny($filename, ['xray', 'x-ray', 'cxr', 'chest', 'radiograph'])) {
            return ['modality' => Modality::Xray, 'confidence' => 0.85];
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private function filenameContainsAny(string $filename, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($filename, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function filenameHasToken(string $filename, string $token): bool
    {
        $base = strtolower((string) pathinfo($filename, PATHINFO_FILENAME));
        $parts = preg_split('/[^a-z0-9]+/', $base, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array(strtolower($token), $parts, true);
    }

    private function modalityFromDicomHint(string $filename): Modality
    {
        if (str_contains($filename, 'mri') || str_contains($filename, 'mr_') || $this->filenameHasToken($filename, 'mr')) {
            return Modality::Mri;
        }

        if ($this->filenameHasToken($filename, 'ct')) {
            return Modality::Ct;
        }

        return Modality::Xray;
    }

    private function modalityFromDicomFile(MedicalRecord $record): ?Modality
    {
        $path = $record->file_path;
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $bytes = @file_get_contents(Storage::disk('local')->path($path), false, null, 0, 2_000_000);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        if (preg_match('/\x08\x00\x60\x00CS(.{2})([A-Z]{2})/s', $bytes, $m) === 1) {
            return $this->mapDicomModalityCode(trim($m[2]));
        }

        if (preg_match('/\b(CT|MR|CR|DX|PX|XA|RF|MG|US|PT|NM)\b/', $bytes, $m) === 1) {
            return $this->mapDicomModalityCode($m[1]);
        }

        return null;
    }

    private function mapDicomModalityCode(string $code): Modality
    {
        return match (strtoupper($code)) {
            'CT', 'PT' => Modality::Ct,
            'MR' => Modality::Mri,
            default => Modality::Xray,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{code: string, flags: list<string>}
     */
    public function applyGuardrails(array $result): array
    {
        $flags = ['medical_disclaimer_required', 'not_a_diagnosis'];
        $confidence = (float) ($result['overall_confidence'] ?? 1);

        foreach ($result['findings'] ?? [] as $finding) {
            if (($finding['severity'] ?? '') === 'critical') {
                $flags[] = 'critical_value_escalation';
            }
        }

        foreach ($result['biomarkers'] ?? [] as $marker) {
            if (($marker['status'] ?? '') === 'critical') {
                $flags[] = 'critical_value_escalation';
            }
        }

        if ($confidence < 0.50) {
            $flags[] = 'low_confidence_abstention';
        } elseif ($confidence < 0.80) {
            $flags[] = 'confidence_hedge';
        } else {
            $flags[] = 'confidence_publish';
        }

        if (! empty($result['rag_weak'])) {
            $flags[] = 'weak_guideline_grounding';
            if ($confidence < 0.50) {
                $flags[] = 'low_confidence_abstention';
            }
        }

        $flags = array_values(array_unique($flags));
        $warn = in_array('critical_value_escalation', $flags, true)
            || in_array('low_confidence_abstention', $flags, true);

        return [
            'code' => $warn ? 'WARN' : 'ALLOW',
            'flags' => $flags,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int|string, mixed>  $guardrails
     * @return array<string, mixed>
     */
    public function composeReports(MedicalRecord $record, array $result, array $guardrails = []): array
    {
        $normalized = MedicalRecord::normalizeGuardrails(
            isset($guardrails['flags']) ? $guardrails : ['flags' => $guardrails]
        );
        $flagList = $normalized['flags'];
        $code = $normalized['code'];

        $language = $record->language;
        $findings = is_array($result['findings'] ?? null) ? $result['findings'] : [];
        $citations = is_array($result['citations'] ?? null) ? array_values($result['citations']) : [];
        $confidence = (float) ($result['overall_confidence'] ?? 0);
        $hedge = in_array('confidence_hedge', $flagList, true);
        $abstain = in_array('low_confidence_abstention', $flagList, true);
        $critical = in_array('critical_value_escalation', $flagList, true);
        $warn = $code === 'WARN';

        $citationGrounding = $this->guidelineGrounding($citations);
        $prose = $this->radiologyProse($result);
        $hasProse = $prose['findings_narrative'] !== '' || $prose['impression'] !== '' || $prose['draft'] !== '';
        $physicianSummary = $hasProse
            ? ($prose['impression'] !== '' ? $prose['impression'] : ($prose['findings_narrative'] !== '' ? $prose['findings_narrative'] : $prose['draft']))
            : $this->physicianSummary($findings, $language, $hedge, $abstain);
        $physicianSummary = $this->withGuidelineBasis($physicianSummary, $citationGrounding);
        $impression = $prose['impression'];

        $engine = (string) ($result['engine'] ?? 'medgemma');
        $adapter = (string) ($result['adapter'] ?? (config('services.modal.lora_path') ? 'configured' : 'none'));
        $adapterUsed = array_key_exists('adapter_used', $result)
            ? (bool) $result['adapter_used']
            : true;
        $modalityLabel = ($record->detected_modality ?? $record->modality)->label();

        $technicalNotes = $abstain
            ? 'Patient report withheld: low confidence or weak guideline grounding.'
            : $this->formatTechnicalNotes($engine, $adapter, $modalityLabel, $code, $adapterUsed);

        if ($warn) {
            $technicalNotes .= ' Patient-facing prose vetoed (WARN).';
        }

        $physicianReport = [
            'summary' => $physicianSummary,
            'findings_narrative' => $prose['findings_narrative'],
            'impression' => $impression,
            'guideline_grounding' => $citationGrounding,
            'medgemma_draft' => $prose['draft'],
            'differential_diagnosis' => $result['differential_diagnosis'] ?? [],
            'recommendations' => $this->composeRecommendations($result, $language, $critical),
            'technical_notes' => $technicalNotes,
            'confidence_band' => $abstain ? 'abstain' : ($hedge ? 'hedge' : 'publish'),
            'engine' => $engine,
            'adapter' => $adapter,
            'guardrail_code' => $code,
        ];

        $patientReport = null;
        if (! $warn && ! $abstain && ! $critical) {
            $patientReport = $this->patientReportFromDraft($result);
        }

        return [
            'physician_report' => $physicianReport,
            'patient_report' => $patientReport,
        ];
    }

    /**
     * @param  array<int, mixed>  $citations
     * @return array<int, array{source: string, section: string, excerpt: string}>
     */
    private function guidelineGrounding(array $citations): array
    {
        $out = [];
        foreach (array_slice($citations, 0, 5) as $citation) {
            if (! is_array($citation)) {
                continue;
            }
            $excerpt = trim((string) ($citation['excerpt'] ?? ''));
            $excerpt = rtrim($excerpt, '.…');
            if ($excerpt === '') {
                continue;
            }
            $out[] = [
                'source' => (string) ($citation['source'] ?? ''),
                'section' => (string) ($citation['section'] ?? ''),
                'excerpt' => $excerpt,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{source: string, section: string, excerpt: string}>  $grounding
     */
    private function withGuidelineBasis(string $text, array $grounding): string
    {
        if ($grounding === []) {
            return $text;
        }

        $lines = collect($grounding)
            ->values()
            ->map(function (array $row, int $i): string {
                $section = $row['section'] !== '' ? ' §'.$row['section'] : '';

                return '['.($i + 1).'] '.$row['source'].$section.': '.$row['excerpt'];
            })
            ->implode("\n");

        $text = rtrim($text);

        return $text === '' ? $lines : $text."\n\nGuideline basis:\n".$lines;
    }

    /**
     * Keep MedGemma prose. JSON finding cards stay separate for overlay.
     *
     * @param  array<string, mixed>  $result
     * @return array{draft: string, findings_narrative: string, impression: string}
     */
    private function radiologyProse(array $result): array
    {
        $draft = MedgemmaDraft::cleanLab(trim((string) ($result['medgemma_draft'] ?? '')));
        $narrative = MedgemmaDraft::cleanLab(trim((string) ($result['findings_narrative'] ?? '')));
        $impression = MedgemmaDraft::cleanLab(trim((string) ($result['impression'] ?? '')));

        if ($draft !== '') {
            $split = MedgemmaDraft::split($draft);
            if ($split['findings'] !== '' || $split['impression'] !== '') {
                $narrative = $split['findings'];
                $impression = $split['impression'];
            }
        }

        return [
            'draft' => $draft,
            'findings_narrative' => $narrative,
            'impression' => $impression,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function physicianSummary(array $findings, ReportLanguage $language, bool $hedge, bool $abstain): string
    {
        $labels = collect($findings)->pluck('label')->filter()->implode(', ');

        return match ($language) {
            ReportLanguage::Malay => $abstain
                ? 'Keyakinan model terlalu rendah untuk laporan automatik. Penemuan mentah: '.$labels.'. Semakan klinikal diperlukan.'
                : ($hedge ? 'Penemuan awal (keyakinan sederhana): ' : 'Penemuan utama: ').$labels.'. DDx disertakan dengan tahap keyakinan.',
            ReportLanguage::Mandarin => $abstain
                ? '模型置信度过低，无法自动发布。原始发现：'.$labels.'。需要人工临床复核。'
                : ($hedge ? '初步发现（中等置信度）：' : '主要发现：').$labels.'。已附鉴别诊断与置信度。',
            ReportLanguage::Tamil => $abstain
                ? 'மாதிரி நம்பிக்கை குறைவு. கண்டறிதல்கள்: '.$labels.'. மருத்துவர் மறுஆய்வு தேவை.'
                : ($hedge ? 'முதற்கட்ட கண்டுபிடிப்புகள்: ' : 'முக்கிய கண்டுபிடிப்புகள்: ').$labels.'.',
            default => $abstain
                ? 'Model confidence too low for automated release. Raw findings: '.$labels.'. Manual clinical review required.'
                : ($hedge ? 'Preliminary findings (moderate confidence): ' : 'Key findings: ').$labels.'. Differential diagnosis with confidence scores included.',
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function composeRecommendations(array $result, ReportLanguage $language, bool $critical): array
    {
        $recs = [];
        foreach ($result['recommendations'] ?? [] as $rec) {
            if (is_string($rec) && trim($rec) !== '') {
                $recs[] = trim($rec);
            }
        }

        $disclaimer = match ($language) {
            ReportLanguage::Malay => 'Kaitkan secara klinikal; jangan guna AI sebagai diagnosis muktamad.',
            ReportLanguage::Mandarin => '请结合临床表现；勿将 AI 输出视为最终诊断。',
            ReportLanguage::Tamil => 'மருத்துவ அறிகுறிகளுடன் ஒப்பிடுக; AI முடிவை இறுதி நோயறிவாக எடுத்துக்கொள்ள வேண்டாம்.',
            default => 'Correlate clinically; do not treat AI output as a final diagnosis.',
        };

        if (! in_array($disclaimer, $recs, true)) {
            $recs[] = $disclaimer;
        }

        if ($critical) {
            $recs[] = match ($language) {
                ReportLanguage::Malay => 'Nilai kritikal: eskalasi segera kepada pakar yang bertugas.',
                ReportLanguage::Mandarin => '危急值：立即上报值班医师。',
                ReportLanguage::Tamil => 'முக்கிய மதிப்பு: உடனடி மருத்துவர் அறிவிப்பு தேவை.',
                default => 'Critical value: escalate immediately to the covering clinician.',
            };
        }

        return $recs;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{summary: string, what_this_means: string, questions_for_doctor: array<int, string>, action_plan: array<int, string>}|null
     */
    private function patientReportFromDraft(array $result): ?array
    {
        $payload = $result['patient_report'] ?? null;
        if (! is_array($payload)) {
            return null;
        }

        $summary = trim((string) ($payload['summary'] ?? ''));
        if ($summary === '') {
            return null;
        }

        $questions = [];
        foreach ($payload['questions_for_doctor'] ?? [] as $question) {
            if (is_string($question) && trim($question) !== '') {
                $questions[] = trim($question);
            }
        }

        $actions = [];
        foreach ($payload['action_plan'] ?? [] as $action) {
            if (is_string($action) && trim($action) !== '') {
                $actions[] = trim($action);
            }
        }

        return [
            'summary' => $summary,
            'what_this_means' => trim((string) ($payload['what_this_means'] ?? '')),
            'questions_for_doctor' => $questions,
            'action_plan' => $actions,
        ];
    }

    /**
     * @param  array<int, string>  $steps
     * @return array<int, array<string, string>>
     */
    public function formatPipelineSteps(array $steps, ?string $running = null): array
    {
        $labels = [
            'upload' => 'Upload received',
            'deidentify' => 'PII de-identified',
            'route' => 'Modality routed',
            'analyze' => 'Model analysis',
            'rag' => 'Hybrid RAG (BM25+dense+MMR)',
            'guardrail' => 'Safety guardrails',
            'compose' => 'Report composed',
        ];

        $all = ['upload', 'deidentify', 'route', 'analyze', 'rag', 'guardrail', 'compose'];

        return collect($all)->map(function (string $step) use ($steps, $running, $labels, $all) {
            $status = 'pending';

            if ($running === $step) {
                $status = 'running';
            } elseif (in_array($step, $steps, true)) {
                $status = 'completed';
            } elseif ($running !== null) {
                $runningIndex = array_search($running, $all, true);
                $stepIndex = array_search($step, $all, true);
                if ($runningIndex !== false && $stepIndex !== false && $stepIndex < $runningIndex) {
                    $status = 'completed';
                }
            }

            return [
                'step' => $step,
                'label' => $labels[$step] ?? $step,
                'status' => $status,
            ];
        })->all();
    }
}
