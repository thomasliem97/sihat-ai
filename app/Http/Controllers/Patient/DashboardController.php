<?php

namespace App\Http\Controllers\Patient;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Models\Biomarker;
use App\Models\MedicalRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $userId = $request->user()->id;

        $records = MedicalRecord::query()
            ->where('user_id', $userId)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (MedicalRecord $r) => [
                'id' => $r->id,
                'title' => $r->title,
                'status' => $r->status->value,
                'modality_label' => ($r->detected_modality ?? $r->modality)->label(),
                'overall_confidence' => $r->overall_confidence,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        $trendBiomarkers = Biomarker::query()
            ->where('user_id', $userId)
            ->orderBy('collected_at')
            ->get()
            ->groupBy('name')
            ->map(function ($group) {
                /** @var Collection<int, Biomarker> $group */
                $first = $group->first();

                return [
                    'unit' => $first->unit ?? '',
                    'reference_low' => $first->reference_low,
                    'reference_high' => $first->reference_high,
                    'points' => $group->map(fn (Biomarker $b) => [
                        'value' => $b->value,
                        'collected_at' => $b->collected_at->toIso8601String(),
                        'status' => $b->status->value,
                    ])->values(),
                ];
            });

        return Inertia::render('patient/Dashboard', [
            'stats' => [
                'total_records' => MedicalRecord::where('user_id', $userId)->count(),
                'completed' => MedicalRecord::where('user_id', $userId)->where('status', RecordStatus::Completed)->count(),
                'abnormal_results' => Biomarker::where('user_id', $userId)->whereIn('status', ['abnormal', 'critical'])->count(),
            ],
            'recentRecords' => $records,
            'biomarkerTrends' => $trendBiomarkers,
        ]);
    }
}
