<?php

namespace App\Http\Controllers;

use App\Jobs\RunEvalSuite;
use App\Models\EvalRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EvalDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $runs = EvalRun::query()->latest()->limit(30)->get()->map(fn (EvalRun $run) => [
            'id' => $run->id,
            'run_type' => $run->run_type,
            'sample_count' => $run->sample_count,
            'avg_score' => $run->avg_score,
            'metrics' => $run->metrics,
            'demo_seed' => (bool) data_get($run->metrics, 'demo_seed', false),
            'created_at' => $run->created_at?->toIso8601String(),
        ]);

        $latestPreferLive = function (string $type) use ($runs): ?array {
            $typed = $runs->where('run_type', $type)->values();
            $live = $typed->firstWhere('demo_seed', false);
            $row = $live ?? $typed->first();

            return $row;
        };

        $medqa = $latestPreferLive('medqa');
        $judge = $latestPreferLive('llm_judge');
        $safety = $latestPreferLive('safety');

        return Inertia::render('eval/Index', [
            'runs' => $runs,
            'summary' => [
                'medqa_accuracy' => $medqa['avg_score'] ?? 0,
                'report_quality' => $judge['avg_score'] ?? 0,
                'safety_compliance' => $safety['avg_score'] ?? 0,
                'medqa_demo_seed' => (bool) ($medqa['demo_seed'] ?? false),
                'report_quality_demo_seed' => (bool) ($judge['demo_seed'] ?? false),
                'safety_demo_seed' => (bool) ($safety['demo_seed'] ?? false),
            ],
            'canRun' => $request->user()?->isPhysician() ?? false,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isPhysician(), 403);

        $validated = $request->validate([
            'suite' => ['required', 'in:medqa,llm_judge,safety'],
        ]);

        RunEvalSuite::dispatch($validated['suite']);

        return back()->with('success', 'Eval suite queued: '.$validated['suite']);
    }
}
