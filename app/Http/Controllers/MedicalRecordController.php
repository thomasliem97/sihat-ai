<?php

namespace App\Http\Controllers;

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Http\Requests\StoreMedicalRecordRequest;
use App\Http\Requests\StoreRecordExplainRequest;
use App\Http\Requests\UpdateMedicalRecordReportRequest;
use App\Models\MedicalRecord;
use App\Models\RecordExplainerMessage;
use App\Models\User;
use App\Services\AiPipelineService;
use App\Services\RecordExplainerService;
use App\Services\RecordTitleGenerator;
use App\Services\SimilarCaseService;
use App\Support\MedgemmaDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicalRecordController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MedicalRecord::class);

        $query = MedicalRecord::query()
            ->with(['user:id,name,role', 'subjectUser:id,name', 'analysisJob'])
            ->latest();

        if ($request->user()->isPatient()) {
            $query->where('user_id', $request->user()->id);
        }

        $records = $query->paginate(15)->through(fn (MedicalRecord $record) => [
            'id' => $record->id,
            'title' => $record->title,
            'modality' => ($record->detected_modality ?? $record->modality)->value,
            'modality_label' => ($record->detected_modality ?? $record->modality)->label(),
            'detected_modality' => $record->detected_modality?->value,
            'status' => $record->status->value,
            'overall_confidence' => $record->overall_confidence,
            'patient_name' => $record->patientDisplayName(),
            'created_at' => $record->created_at?->toIso8601String(),
            'analyzed_at' => $record->analyzed_at?->toIso8601String(),
        ]);

        return Inertia::render('records/Index', [
            'records' => $records,
            'isPhysician' => $request->user()->isPhysician(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', MedicalRecord::class);

        $patients = $request->user()->isPhysician()
            ? User::patientsForSelect()
            : collect();

        return Inertia::render('records/Create', [
            'modalities' => collect(Modality::cases())->map(fn (Modality $m) => [
                'value' => $m->value,
                'label' => $m->label(),
            ]),
            'languages' => collect(ReportLanguage::cases())->map(fn (ReportLanguage $l) => [
                'value' => $l->value,
                'label' => match ($l) {
                    ReportLanguage::English => 'English',
                    ReportLanguage::Malay => 'Bahasa Melayu',
                    ReportLanguage::Mandarin => 'Mandarin',
                    ReportLanguage::Tamil => 'Tamil',
                },
            ]),
            'patients' => $patients,
            'isPhysician' => $request->user()->isPhysician(),
        ]);
    }

    public function store(StoreMedicalRecordRequest $request, AiPipelineService $pipeline): RedirectResponse
    {
        $this->authorize('create', MedicalRecord::class);

        $file = $request->file('file');
        $path = $file->store('medical-records', 'local');
        $uploader = $request->user();

        if ($uploader->isPhysician()) {
            $subjectUserId = $request->filled('patient_id') ? $request->integer('patient_id') : null;
            $chartOwnerId = $subjectUserId ?? $uploader->id;
        } else {
            $chartOwnerId = $uploader->id;
            $subjectUserId = $request->string('subject')->toString() === 'self'
                ? $uploader->id
                : null;
        }

        $title = trim($request->string('title')->toString());
        $titleGenerated = $title === '';

        $record = MedicalRecord::create([
            'user_id' => $chartOwnerId,
            'uploaded_by_user_id' => $uploader->id,
            'subject_user_id' => $subjectUserId,
            'title' => $titleGenerated
                ? RecordTitleGenerator::fromFilename($file->getClientOriginalName())
                : $title,
            'title_generated' => $titleGenerated,
            'modality' => $request->enum('modality', Modality::class) ?? Modality::Unknown,
            'status' => RecordStatus::Pending,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'language' => $request->enum('language', ReportLanguage::class) ?? ReportLanguage::English,
        ]);

        $pipeline->dispatch($record);

        return redirect()->route('records.show', $record)
            ->with('success', 'Record uploaded. Analysis in progress.');
    }

    public function show(Request $request, MedicalRecord $record, SimilarCaseService $similarCases, RecordExplainerService $explainer): Response
    {
        $this->authorize('view', $record);

        $record->load([
            'user:id,name,role',
            'subjectUser:id,name',
            'biomarkers',
            'analysisJob',
            'signedByUser:id,name',
        ]);

        $viewMode = $request->user()->isPhysician() ? 'physician' : 'patient';
        $flags = $record->guardrailFlagList();
        $guardrailCode = $record->guardrailCode();
        $withholdPatient = $record->withholdsPatientContent();
        $awaitingSign = $viewMode === 'patient'
            && $record->status === RecordStatus::Completed
            && ! $record->isSigned();

        $patientReport = $record->patient_report;
        if ($viewMode === 'patient' && ($withholdPatient || $awaitingSign)) {
            $patientReport = null;
        }

        $physicianReport = $viewMode === 'physician'
            ? ($record->signed_physician_report ?? $record->physician_report)
            : null;

        $safeUri = $record->safe_file_path
            ? URL::temporarySignedRoute('ai.file', now()->addHours(2), ['record' => $record->id])
            : null;

        return Inertia::render('records/Show', [
            'record' => [
                'id' => $record->id,
                'title' => $record->title,
                'modality' => $record->modality->value,
                'modality_label' => $record->modality->label(),
                'detected_modality' => $record->detected_modality?->value,
                'detected_modality_label' => $record->detected_modality?->label(),
                'status' => $record->status->value,
                'overall_confidence' => $record->overall_confidence,
                'findings' => $viewMode === 'patient' && ($withholdPatient || $awaitingSign) ? null : $record->findings,
                'partial_findings' => $viewMode === 'physician' ? $record->partial_findings : null,
                'physician_report' => MedgemmaDraft::scrubReport($physicianReport),
                'patient_report' => $patientReport,
                'patient_report_withheld' => $viewMode === 'patient' && $withholdPatient,
                'patient_awaiting_sign' => $awaitingSign,
                'citations' => $viewMode === 'patient' && ($withholdPatient || $awaitingSign) ? null : $record->citations,
                'bounding_boxes' => $viewMode === 'patient' && ($withholdPatient || $awaitingSign) ? null : $record->bounding_boxes,
                'longitudinal_diff' => $viewMode === 'physician' ? $record->longitudinal_diff : null,
                'volume_meta' => $viewMode === 'physician' ? $record->volume_meta : null,
                'patch_meta' => $viewMode === 'physician' ? $record->patch_meta : null,
                'guardrail_flags' => $flags,
                'guardrail_code' => $guardrailCode,
                'safe_uri' => $viewMode === 'physician' ? $safeUri : null,
                'pipeline_steps' => $record->pipeline_steps,
                'agent_trace' => $viewMode === 'physician' ? $record->agent_trace : null,
                'is_signed' => $record->isSigned(),
                'signed_at' => $record->signed_at?->toIso8601String(),
                'signed_by_name' => $record->signedByUser?->name,
                'can_edit_report' => $viewMode === 'physician' && $record->status === RecordStatus::Completed && ! $record->isSigned(),
                'can_retry' => $record->status === RecordStatus::Failed,
                'error_message' => $record->error_message,
                'patient_name' => $record->patientDisplayName(),
                'file_url' => $record->status === RecordStatus::Completed
                    && ($viewMode === 'physician' || ! ($withholdPatient || $awaitingSign))
                    ? route('records.file', $record)
                    : null,
                'created_at' => $record->created_at?->toIso8601String(),
                'analyzed_at' => $record->analyzed_at?->toIso8601String(),
            ],
            'similarCases' => $viewMode === 'physician' && $record->status === RecordStatus::Completed
                ? $similarCases->retrieve($record)
                : [],
            'biomarkers' => ($viewMode === 'patient' && ($withholdPatient || $awaitingSign))
                ? []
                : $record->biomarkers->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'value' => $b->value,
                    'unit' => $b->unit,
                    'reference_low' => $b->reference_low,
                    'reference_high' => $b->reference_high,
                    'status' => $b->status->value,
                    'collected_at' => $b->collected_at->toIso8601String(),
                ]),
            'viewMode' => $viewMode,
            'canExplain' => $request->user()->can('explain', $record),
            'explainerMessages' => $request->user()->can('explain', $record)
                ? $record->explainerMessages()
                    ->orderBy('id')
                    ->get()
                    ->map(fn (RecordExplainerMessage $message) => $this->explainerPayload($message))
                : [],
            'explainerSuggestions' => $request->user()->can('explain', $record)
                ? $explainer->suggestions($request->user()->isPhysician(), $record)
                : [],
        ]);
    }

    public function updateReport(UpdateMedicalRecordReportRequest $request, MedicalRecord $record): RedirectResponse
    {
        $this->authorize('updateReport', $record);

        abort_unless($record->status === RecordStatus::Completed, 422);
        abort_if($record->isSigned(), 422, 'Report is already signed.');

        $validated = $request->validated();

        $report = $record->physician_report ?? [];
        $report['summary'] = $validated['summary'];
        if (array_key_exists('findings_narrative', $validated)) {
            $report['findings_narrative'] = $validated['findings_narrative'];
        }
        if (array_key_exists('impression', $validated)) {
            $report['impression'] = $validated['impression'];
        }
        if (array_key_exists('recommendations', $validated)) {
            $report['recommendations'] = $validated['recommendations'] ?? [];
        }
        if (array_key_exists('technical_notes', $validated)) {
            $report['technical_notes'] = $validated['technical_notes'];
        }

        $record->update(['physician_report' => $report]);

        return back()->with('success', 'Physician draft updated.');
    }

    public function sign(Request $request, MedicalRecord $record): RedirectResponse
    {
        $this->authorize('sign', $record);

        abort_unless($record->status === RecordStatus::Completed, 422);
        abort_if($record->isSigned(), 422, 'Report is already signed.');
        abort_if($record->physician_report === null || $record->physician_report === [], 422, 'No physician report to sign.');

        $record->update([
            'signed_physician_report' => $record->physician_report,
            'signed_by' => $request->user()->id,
            'signed_at' => now(),
        ]);

        return back()->with('success', 'Physician report signed.');
    }

    public function retry(MedicalRecord $record, AiPipelineService $pipeline): RedirectResponse
    {
        $this->authorize('retry', $record);

        $pipeline->retry($record);

        return redirect()->route('records.show', $record)
            ->with('success', 'Analysis restarted.');
    }

    public function file(Request $request, MedicalRecord $record): mixed
    {
        $this->authorize('view', $record);

        abort_unless($record->status === RecordStatus::Completed, 403);

        if ($request->user()->isPatient()) {
            abort_if($record->withholdsPatientContent() || ! $record->isSigned(), 403);
        }

        if (! Storage::disk('local')->exists($record->file_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($record->file_path, $record->original_filename);
    }

    public function explain(StoreRecordExplainRequest $request, MedicalRecord $record, RecordExplainerService $explainer): JsonResponse|StreamedResponse
    {
        $this->authorize('explain', $record);

        $validated = $request->validated();
        $box = $validated['selected_box'] ?? null;
        if (is_array($box) && $box === []) {
            $box = null;
        }

        $findingIndex = $validated['finding_index'] ?? null;
        if ($findingIndex !== null) {
            $findingIndex = (int) $findingIndex;
        }

        if (str_contains((string) $request->header('Accept'), 'text/event-stream')) {
            return $explainer->streamAsk(
                $record,
                $request->user(),
                $validated['question'],
                $findingIndex,
                is_array($box) ? $box : null,
            );
        }

        $pair = $explainer->ask(
            $record,
            $request->user(),
            $validated['question'],
            $findingIndex,
            is_array($box) ? $box : null,
        );

        return response()->json([
            'messages' => [
                $this->explainerPayload($pair['user']),
                $this->explainerPayload($pair['assistant']),
            ],
            'suggestions' => $explainer->nextSuggestions(
                $request->user()->isPhysician(),
                $validated['question'],
                $pair['assistant']->content,
                $record->findings ?? [],
                $record,
            ),
        ]);
    }

    /**
     * @return array{id: int, role: string, content: string, finding_index: int|null, created_at: string|null}
     */
    private function explainerPayload(RecordExplainerMessage $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'content' => $message->content,
            'finding_index' => $message->finding_index,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
