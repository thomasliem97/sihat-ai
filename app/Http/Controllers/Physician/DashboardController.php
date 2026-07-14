<?php

namespace App\Http\Controllers\Physician;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Models\Biomarker;
use App\Models\MedicalRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $records = MedicalRecord::query()
            ->with('user:id,name')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (MedicalRecord $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'status' => $r->status->value,
                'patient_name' => $r->user->name,
                'modality_label' => $r->modality->label(),
                'overall_confidence' => $r->overall_confidence,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return Inertia::render('physician/Dashboard', [
            'stats' => [
                'total_records' => MedicalRecord::count(),
                'pending' => MedicalRecord::where('status', RecordStatus::Processing)->count(),
                'completed' => MedicalRecord::where('status', RecordStatus::Completed)->count(),
                'patients' => User::where('role', 'patient')->count(),
                'critical_flags' => MedicalRecord::whereNotNull('guardrail_flags')
                    ->where(function ($q) {
                        $q->whereJsonContains('guardrail_flags->flags', 'critical_value_escalation')
                            ->orWhereJsonContains('guardrail_flags', 'critical_value_escalation');
                    })
                    ->count(),
            ],
            'recentRecords' => $records,
            'criticalBiomarkers' => Biomarker::query()
                ->whereIn('status', ['critical', 'abnormal'])
                ->with('user:id,name')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Biomarker $b) => [
                    'name' => $b->name,
                    'value' => $b->value,
                    'unit' => $b->unit,
                    'status' => $b->status->value,
                    'patient_name' => $b->user->name,
                ]),
        ]);
    }
}
