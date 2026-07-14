<?php

namespace App\Http\Controllers;

use App\Enums\ClinicalFlag;
use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Models\AnalysisJob;
use App\Models\Biomarker;
use App\Services\AiPipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiWebhookController extends Controller
{
    public function __invoke(Request $request, AiPipelineService $pipeline): JsonResponse
    {
        if (! $this->signatureValid($request)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $validated = $request->validate([
            'job_id' => ['required', 'string'],
            'status' => ['required', 'string'],
            'result' => ['nullable', 'array'],
            'error' => ['nullable', 'string'],
            'detected_modality' => ['nullable', 'string'],
            'route_confidence' => ['nullable', 'numeric'],
        ]);

        $job = AnalysisJob::query()
            ->where('external_job_id', $validated['job_id'])
            ->firstOrFail();

        $record = $job->medicalRecord;

        if ($job->status === 'completed') {
            return response()->json(['ok' => true, 'message' => 'already completed']);
        }

        if ($validated['status'] === 'failed') {
            $record->update([
                'status' => RecordStatus::Failed,
                'error_message' => $validated['error'] ?? 'Analysis failed',
            ]);
            $job->update([
                'status' => 'failed',
                'error_message' => $validated['error'] ?? 'Analysis failed',
                'completed_at' => now(),
            ]);

            return response()->json(['ok' => true]);
        }

        try {
            $raw = $validated['result'] ?? [];

            if (! empty($validated['detected_modality'])) {
                $modality = Modality::tryFrom($validated['detected_modality']);
                if ($modality) {
                    $record->update([
                        'detected_modality' => $modality,
                        'route_confidence' => $validated['route_confidence'] ?? $record->route_confidence,
                    ]);
                }
            }

            $result = $pipeline->completeFromWebhook($record, $job, $raw);
            $pipeline->persistCompleted($record, $job, $result);

            foreach ($result['biomarkers'] ?? $raw['biomarkers'] ?? [] as $data) {
                Biomarker::create([
                    'user_id' => $record->user_id,
                    'medical_record_id' => $record->id,
                    'name' => $data['name'],
                    'value' => $data['value'],
                    'unit' => $data['unit'],
                    'reference_low' => $data['reference_low'] ?? null,
                    'reference_high' => $data['reference_high'] ?? null,
                    'status' => ClinicalFlag::from($data['status']),
                    'collected_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('AI webhook completion failed', [
                'job_id' => $validated['job_id'],
                'error' => $e->getMessage(),
            ]);

            $record->update([
                'status' => RecordStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            $job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true]);
    }

    private function signatureValid(Request $request): bool
    {
        $secret = (string) config('services.sihat_ai.webhook_secret');

        if ($secret === '') {
            return app()->environment('local', 'testing');
        }

        $provided = (string) $request->header('X-Sihat-Signature', '');
        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $provided);
    }
}
