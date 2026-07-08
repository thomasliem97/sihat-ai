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
            'created_at' => $run->created_at?->toIso8601String(),
        ]);

        $latest = fn (string $type) => $runs->firstWhere('run_type', $type);

        return Inertia::render('eval/Index', [
            'runs' => $runs,
            'summary' => [
                'medqa_accuracy' => $latest('medqa')?->avg_score ?? 0,
                'report_quality' => $latest('llm_judge')?->avg_score ?? 0,
                'safety_compliance' => $latest('safety')?->avg_score ?? 0,
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
