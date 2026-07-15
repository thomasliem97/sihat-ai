<?php

namespace App\Services;

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Jobs\ProcessMedicalRecord;
use App\Models\AnalysisJob;
use App\Models\AuditEvent;
use App\Models\MedicalRecord;
use App\Notifications\CriticalEscalationNotification;
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
    ) {}

    public function dispatch(MedicalRecord $record): AnalysisJob
    {
        $record->update(['status' => RecordStatus::Processing]);

        $job = AnalysisJob::create([
            'medical_record_id' => $record->id,
            'status' => 'pending',
            'external_job_id' => (string) Str::uuid(),
            'started_at' => now(),
        ]);

        ProcessMedicalRecord::dispatch($record, $job);

        return $job;
    }

    /**
     * De-identify, route, and hand off to FastAPI. Record stays processing until webhook.
     */
    public function beginRemoteAnalysis(MedicalRecord $record, AnalysisJob $job): void
    {
        $this->prepareRecord($record, $job);

        $modality = $record->detected_modality ?? $record->modality;
        $baseUrl = rtrim((string) config('services.modal.url'), '/');
        $webhookUrl = rtrim((string) config('app.url'), '/').'/api/ai/webhook';
        $path = $record->inferenceFilePath();
        $bytes = Storage::disk('local')->get($path);

        if ($bytes === null || $bytes === '') {
            throw new \RuntimeException('Inference file missing for analyze handoff: '.$path);
        }

        // Always embed bytes. Modal cannot pull trycloudflare/ngrok signed URLs (datacenter IP blocked).
        $response = Http::timeout(120)
            ->post("{$baseUrl}/api/v1/analyze", [
                'job_id' => $job->external_job_id,
                'record_id' => $record->id,
                'modality' => $modality->value,
                'file_path' => $path,
                'file_b64' => base64_encode($bytes),
                'language' => $record->language->value,
                'webhook_url' => $webhookUrl,
                'mime_type' => $record->mime_type,
                'original_filename' => $record->original_filename,
                'route_confidence' => $record->route_confidence,
                'engine' => 'medgemma',
                'adapter' => config('services.modal.lora_path') ? 'configured' : 'none',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'AI service rejected analyze request: HTTP '.$response->status()
            );
        }

        $job->update([
            'status' => 'running',
            'steps_completed' => ['upload', 'deidentify', 'route', 'analyze'],
        ]);

        $record->update([
            'pipeline_steps' => $this->formatPipelineSteps(
                ['upload', 'deidentify', 'route', 'analyze'],
                running: 'analyze',
            ),
        ]);
    }

    /**
     * Complete analysis after FastAPI webhook delivers raw findings.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function completeFromWebhook(MedicalRecord $record, AnalysisJob $job, array $result): array
    {
        return $this->finalizeResult($record, $job, $result);
    }

    /**
     * Persist completed analysis onto the record and job.
     *
     * @param  array<string, mixed>  $result
     */
    public function persistCompleted(MedicalRecord $record, AnalysisJob $job, array $result): void
    {
        $embedding = $this->similarCases->embedResult($record, $result);

        $record->update([
            'status' => RecordStatus::Completed,
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

        $this->deidentification->deidentify($record);
        $record->refresh();
        $record->update(['deidentified_at' => now()]);
        $job->update(['steps_completed' => ['upload', 'deidentify']]);

        $routed = $this->detectModality($record);
        $record->update([
            'detected_modality' => $routed['modality'],
            'route_confidence' => $routed['confidence'],
        ]);
        $job->update(['steps_completed' => ['upload', 'deidentify', 'route']]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finalizeResult(MedicalRecord $record, AnalysisJob $job, array $result): array
    {
        $trace = [];
        $modalityEnum = $record->detected_modality ?? $record->modality;
        $modality = $modalityEnum->value;
        $result['engine'] = $result['engine'] ?? 'medgemma';
        $result['adapter'] = $result['adapter'] ?? (config('services.modal.lora_path') ? 'configured' : 'none');
        $result['findings'] = $this->sanitizeImagingFindings($modalityEnum, $result);
        if ($this->imagingFindingsNeedReview($modalityEnum, $result['findings'])) {
            $result['overall_confidence'] = min((float) ($result['overall_confidence'] ?? 1), 0.35);
        }
        $result['differential_diagnosis'] = $this->sanitizeDifferential(
            $result['differential_diagnosis'] ?? []
        );

        $trace[] = $this->hop('router', 'completed', 'Modality '.$modality, $record->route_confidence);
        if ($record->safe_file_path) {
            $trace[] = $this->hop('deidentify', 'completed', 'safe_uri sibling ready', null);
        }

        $t0 = microtime(true);
        $partial = $this->buildPartialFindings($modalityEnum, $result);
        $result['partial_findings'] = $partial;
        $parallelStarted = microtime(true);
        $trace[] = $this->hop(
            'imaging_specialist',
            $partial['imaging'] !== null ? 'completed' : 'skipped',
            $partial['imaging'] !== null
                ? count($partial['imaging']['findings'] ?? []).' imaging findings'
                : 'N/A for '.$modality,
            $partial['imaging']['overall_confidence'] ?? null,
            $parallelStarted,
        );
        $trace[] = $this->hop(
            'doc_specialist',
            $partial['document'] !== null ? 'completed' : 'skipped',
            $partial['document'] !== null
                ? count($partial['document']['findings'] ?? []).' document findings'
                : 'N/A for '.$modality,
            $partial['document']['overall_confidence'] ?? null,
            $parallelStarted,
        );
        $result['findings'] = $this->mergePartialFindings($partial, $result['findings'] ?? []);
        $trace[] = $this->hop('merge', 'completed', 'partial_findings merged', $result['overall_confidence'] ?? null, $t0);

        $t0 = microtime(true);
        $citations = $this->rag->retrieveCitations($record, $result['findings'] ?? []);
        $result['citations'] = $citations;
        $result['rag_weak'] = $this->rag->wasWeakRetrieval($citations);
        $trace[] = $this->hop(
            'rag',
            'completed',
            count($citations).' citations (BM25+dense+MMR)',
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
            $guardrails['code'].':'.implode(',', $guardrails['flags']),
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
        $trace[] = $this->hop('compose', 'completed', $record->language->value.' dual reports', null, $t0);
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
    private function hop(string $name, string $status, string $detail, ?float $confidence = null, ?float $startedAt = null): array
    {
        $ended = microtime(true);

        return [
            'hop' => $name,
            'status' => $status,
            'detail' => $detail,
            'confidence' => $confidence,
            'duration_ms' => $startedAt ? (int) round(($ended - $startedAt) * 1000) : null,
            'ended_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    public function buildLongitudinalDiff(MedicalRecord $record, array $result): ?array
    {
        $modality = $record->detected_modality ?? $record->modality;

        $prior = MedicalRecord::query()
            ->where('user_id', $record->user_id)
            ->where('id', '!=', $record->id)
            ->where('status', RecordStatus::Completed)
            ->where(function ($q) use ($modality) {
                $q->where('detected_modality', $modality)
                    ->orWhere('modality', $modality);
            })
            ->latest('analyzed_at')
            ->first();

        if (! $prior) {
            return [
                'has_prior' => false,
                'summary' => 'No prior study for this patient and modality.',
                'changes' => [],
            ];
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
        $priorLabels = collect($prior->findings ?? [])->pluck('label')->filter()->map(fn ($l) => mb_strtolower((string) $l));
        $currentLabels = collect($result['findings'] ?? [])->pluck('label')->filter()->map(fn ($l) => mb_strtolower((string) $l));

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
        $current = collect($result['biomarkers'] ?? []);
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
        if ($this->filenameContainsAny($filename, ['fundus', 'retina', 'ophthal', 'cataract', 'glaucoma', 'eyepacs', 'oct'])) {
            return ['modality' => Modality::Ophthalmology, 'confidence' => 0.85];
        }

        if ($this->filenameContainsAny($filename, ['derm', 'skin', 'lesion', 'melanoma', 'nevus', 'isic', 'dermos'])) {
            return ['modality' => Modality::Dermatology, 'confidence' => 0.85];
        }

        if ($this->filenameContainsAny($filename, ['histo', 'pathology', 'pathmnist', 'wsi', 'slide', 'biopsy', 'seminoma', 'pcam'])) {
            return ['modality' => Modality::Histopath, 'confidence' => 0.85];
        }

        if ($this->filenameContainsAny($filename, ['hrct', 'computed tomography', 'computed_tomography'])
            || str_contains($filename, 'ct')) {
            return ['modality' => Modality::Ct, 'confidence' => 0.85];
        }

        if (str_contains($filename, 'mri') || str_contains($filename, 'mr_')) {
            return ['modality' => Modality::Mri, 'confidence' => 0.85];
        }

        if (str_ends_with($filename, '.zip') && (str_contains($filename, 'ct') || str_contains($filename, 'mri'))) {
            return [
                'modality' => str_contains($filename, 'mri') ? Modality::Mri : Modality::Ct,
                'confidence' => 0.8,
            ];
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

    private function modalityFromDicomHint(string $filename): Modality
    {
        if (str_contains($filename, 'mri') || str_contains($filename, 'mr_') || str_contains($filename, 'mr')) {
            return Modality::Mri;
        }

        if (str_contains($filename, 'ct')) {
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
            || in_array('low_confidence_abstention', $flags, true)
            || in_array('weak_guideline_grounding', $flags, true);

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
        $findings = $result['findings'] ?? [];
        $citations = $result['citations'] ?? [];
        $confidence = (float) ($result['overall_confidence'] ?? 0);
        $hedge = in_array('confidence_hedge', $flagList, true);
        $abstain = in_array('low_confidence_abstention', $flagList, true);
        $critical = in_array('critical_value_escalation', $flagList, true);
        $warn = $code === 'WARN';

        $citationNote = collect($citations)
            ->take(3)
            ->map(fn (array $c, int $i) => '['.($i + 1).'] '.$c['source'].' §'.$c['section'])
            ->implode('; ');

        $physicianSummary = $this->physicianSummary($findings, $language, $hedge, $abstain);
        if ($citationNote !== '') {
            $physicianSummary .= ' Guidelines: '.$citationNote.'.';
        }

        $engine = (string) ($result['engine'] ?? 'medgemma');
        $adapter = (string) ($result['adapter'] ?? (config('services.modal.lora_path') ? 'configured' : 'none'));
        $modalityLabel = ($record->detected_modality ?? $record->modality)->label();

        $technicalNotes = $abstain
            ? 'Report withheld from automatic patient release due to low confidence or weak guideline grounding.'
            : sprintf(
                'Analysis engine=%s; adapter=%s; modality=%s; guardrail=%s. RAG: hybrid BM25+dense with MMR.',
                $engine,
                $adapter,
                $modalityLabel,
                $code,
            );

        if ($warn) {
            $technicalNotes .= ' WARN: patient-facing model prose vetoed.';
        }

        $physicianReport = [
            'summary' => $physicianSummary,
            'differential_diagnosis' => $result['differential_diagnosis'] ?? [],
            'recommendations' => $this->physicianRecommendations($findings, $language, $hedge, $abstain, $critical),
            'technical_notes' => $technicalNotes,
            'confidence_band' => $abstain ? 'abstain' : ($hedge ? 'hedge' : 'publish'),
            'engine' => $engine,
            'adapter' => $adapter,
            'guardrail_code' => $code,
        ];

        $patientReport = null;
        if (! $warn && ! $abstain && ! $critical) {
            $patientReport = [
                'summary' => $this->patientSummary($findings, $language, $hedge),
                'what_this_means' => $this->patientExplanation($findings, $language, $hedge),
                'questions_for_doctor' => $this->patientQuestions($language, $hedge),
                'action_plan' => $this->patientActionPlan($language, $hedge),
            ];
        }

        return [
            'physician_report' => $physicianReport,
            'patient_report' => $patientReport,
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
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, string>
     */
    private function physicianRecommendations(array $findings, ReportLanguage $language, bool $hedge, bool $abstain, bool $critical): array
    {
        $recs = match ($language) {
            ReportLanguage::Malay => ['Kaitkan secara klinikal; jangan guna AI sebagai diagnosis muktamad.'],
            ReportLanguage::Mandarin => ['请结合临床表现；勿将 AI 输出视为最终诊断。'],
            ReportLanguage::Tamil => ['மருத்துவ அறிகுறிகளுடன் ஒப்பிடுக; AI முடிவை இறுதி நோயறிவாக எடுத்துக்கொள்ள வேண்டாம்.'],
            default => ['Correlate clinically; do not treat AI output as a final diagnosis.'],
        };

        if ($critical) {
            $recs[] = match ($language) {
                ReportLanguage::Malay => 'Nilai kritikal: eskalasi segera kepada pakar yang bertugas.',
                ReportLanguage::Mandarin => '危急值：立即上报值班医师。',
                ReportLanguage::Tamil => 'முக்கிய மதிப்பு: உடனடி மருத்துவர் அறிவிப்பு தேவை.',
                default => 'Critical value: escalate immediately to the covering clinician.',
            };
        }

        if ($abstain || $hedge) {
            $recs[] = match ($language) {
                ReportLanguage::Malay => 'Semak semula imej/laporan sumber sebelum menasihati pesakit.',
                ReportLanguage::Mandarin => '向患者说明前请复核原始影像/报告。',
                ReportLanguage::Tamil => 'நோயாளியிடம் கூறுவதற்கு முன் மூல அறிக்கையை மறுபரிசீலனை செய்யவும்.',
                default => 'Re-review source image/report before counseling the patient.',
            };
        }

        if ($language === ReportLanguage::English && collect($findings)->where('severity', 'abnormal')->isNotEmpty()) {
            $recs[] = 'Consider follow-up imaging or labs as clinically indicated.';
        }

        return $recs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function patientSummary(array $findings, ReportLanguage $language, bool $hedge): string
    {
        $abnormal = collect($findings)->whereIn('severity', ['abnormal', 'critical', 'borderline']);

        return match ($language) {
            ReportLanguage::Malay => $hedge
                ? 'Keputusan awal mungkin memerlukan semakan lanjut oleh doktor anda.'
                : ($abnormal->isNotEmpty()
                    ? 'Beberapa keputusan memerlukan perhatian doktor anda.'
                    : 'Keputusan kelihatan dalam julat normal.'),
            ReportLanguage::Mandarin => $hedge
                ? '这些是初步结果，可能需要医生进一步查看。'
                : ($abnormal->isNotEmpty()
                    ? '部分结果需要医生关注。'
                    : '结果大致在正常范围。'),
            ReportLanguage::Tamil => $hedge
                ? 'இவை ஆரம்ப முடிவுகள்; மருத்துவர் மேலும் பார்க்க வேண்டியிருக்கலாம்.'
                : ($abnormal->isNotEmpty()
                    ? 'சில முடிவுகளுக்கு மருத்துவர் கவனம் தேவை.'
                    : 'முடிவுகள் சாதாரண வரம்பில் உள்ளன.'),
            default => $hedge
                ? 'These are preliminary results and may need your doctor to look more closely.'
                : ($abnormal->isNotEmpty()
                    ? 'Some results need your doctor\'s attention.'
                    : 'Your results appear to be within normal range.'),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function patientExplanation(array $findings, ReportLanguage $language, bool $hedge): string
    {
        $first = collect($findings)->first();
        $label = is_array($first) ? ($first['label'] ?? 'your study') : 'your study';

        $base = match ($language) {
            ReportLanguage::Malay => "Analisis AI melihat: {$label}. Ini bukan diagnosis; doktor anda akan menerangkan maksudnya.",
            ReportLanguage::Mandarin => "AI 审阅提示：{$label}。这不是诊断，请由医生解释对您的意义。",
            ReportLanguage::Tamil => "AI ஆய்வு குறிப்பு: {$label}. இது நோயறிவு அல்ல; மருத்துவர் விளக்குவார்.",
            default => "The AI review noted: {$label}. This is not a diagnosis; your doctor will explain what it means for you.",
        };

        if (! $hedge) {
            return $base;
        }

        return match ($language) {
            ReportLanguage::Malay => $base.' Keyakinan model adalah sederhana.',
            ReportLanguage::Mandarin => $base.' 模型置信度为中等。',
            ReportLanguage::Tamil => $base.' மாதிரி நம்பிக்கை மிதமானது.',
            default => $base.' The model confidence is moderate.',
        };
    }

    /**
     * @return array<int, string>
     */
    private function patientQuestions(ReportLanguage $language, bool $hedge): array
    {
        return match ($language) {
            ReportLanguage::Malay => $hedge
                ? ['Adakah keputusan ini perlu diulang?', 'Apa yang perlu saya pantau di rumah?']
                : ['Adakah saya perlu rawatan lanjut?', 'Bilakah saya perlu datang semula?'],
            ReportLanguage::Mandarin => $hedge
                ? ['是否需要复查？', '在家需要注意什么？']
                : ['我需要进一步治疗吗？', '何时复诊？'],
            ReportLanguage::Tamil => $hedge
                ? ['இதை மீண்டும் பரிசோதிக்க வேண்டுமா?', 'வீட்டில் எதை கவனிக்க வேண்டும்?']
                : ['மேலும் சிகிச்சை தேவையா?', 'எப்போது மீண்டும் வர வேண்டும்?'],
            default => $hedge
                ? ['Should this test be repeated?', 'What should I watch for at home?']
                : ['Do I need further treatment?', 'When should I follow up?'],
        };
    }

    /**
     * @return array<int, string>
     */
    private function patientActionPlan(ReportLanguage $language, bool $hedge): array
    {
        return match ($language) {
            ReportLanguage::Malay => $hedge
                ? ['Bawa keputusan ini kepada doktor anda', 'Catat sebarang simptom baharu']
                : ['Ikut nasihat doktor anda', 'Rehat secukupnya', 'Pantau simptom'],
            ReportLanguage::Mandarin => $hedge
                ? ['把结果带给医生', '记录任何新症状']
                : ['遵医嘱', '适当休息', '观察症状'],
            ReportLanguage::Tamil => $hedge
                ? ['இந்த முடிவுகளை மருத்துவரிடம் கொண்டு செல்லவும்', 'புதிய அறிகுறிகளை குறிக்கவும்']
                : ['மருத்துவர் ஆலோசனையை பின்பற்றவும்', 'ஓய்வு எடுக்கவும்', 'அறிகுறிகளை கவனிக்கவும்'],
            default => $hedge
                ? ['Bring these results to your doctor', 'Note any new symptoms']
                : ['Follow your doctor\'s advice', 'Rest as needed', 'Monitor your symptoms'],
        };
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
            if (in_array($step, $steps, true)) {
                $status = $running === $step ? 'running' : 'completed';
            } elseif ($running !== null && array_search($step, $all, true) < array_search($running, $all, true)) {
                $status = 'completed';
            }

            return [
                'step' => $step,
                'label' => $labels[$step] ?? $step,
                'status' => $status,
            ];
        })->all();
    }
}
