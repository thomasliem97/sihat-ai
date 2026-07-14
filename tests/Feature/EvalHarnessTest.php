<?php

use App\Jobs\RunEvalSuite;
use App\Models\EvalRun;
use App\Models\User;
use App\Services\EvalHarnessService;
use Illuminate\Support\Facades\Queue;

test('eval harness medqa suite stores a live eval run', function () {
    $result = app(EvalHarnessService::class)->run('medqa');

    expect($result['run'])->toBeInstanceOf(EvalRun::class)
        ->and($result['run']->run_type)->toBe('medqa')
        ->and($result['run']->sample_count)->toBeGreaterThan(0)
        ->and(EvalRun::query()->where('run_type', 'medqa')->count())->toBe(1);
});

test('eval harness safety suite stores a live eval run', function () {
    $result = app(EvalHarnessService::class)->run('safety');

    expect($result['run']->run_type)->toBe('safety')
        ->and($result['run']->metrics['source'] ?? null)->toBe('live_harness');
});

test('physician can view the evaluation dashboard', function () {
    $physician = User::factory()->physician()->create();

    EvalRun::factory()->create([
        'run_type' => 'medqa',
        'avg_score' => 78.5,
        'metrics' => ['demo_seed' => true],
    ]);
    EvalRun::factory()->create([
        'run_type' => 'llm_judge',
        'avg_score' => 4.2,
        'metrics' => ['source' => 'live_harness'],
    ]);
    EvalRun::factory()->create([
        'run_type' => 'safety',
        'avg_score' => 96.0,
        'metrics' => ['demo_seed' => false],
    ]);

    $this->actingAs($physician)
        ->get(route('evaluation.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('eval/Index')
            ->where('summary.medqa_accuracy', 78.5)
            ->where('summary.report_quality', 4.2)
            ->where('summary.safety_compliance', 96)
            ->where('summary.medqa_demo_seed', true)
            ->where('summary.report_quality_demo_seed', false)
            ->where('canRun', true)
            ->has('runs', 3));
});

test('physician can queue an evaluation suite', function () {
    Queue::fake();
    $physician = User::factory()->physician()->create();

    $this->actingAs($physician)
        ->post(route('evaluation.run'), ['suite' => 'medqa'])
        ->assertRedirect();

    Queue::assertPushed(RunEvalSuite::class, fn (RunEvalSuite $job) => $job->suite === 'medqa');
});

test('patient cannot queue an evaluation suite', function () {
    $patient = User::factory()->create();

    $this->actingAs($patient)
        ->post(route('evaluation.run'), ['suite' => 'medqa'])
        ->assertForbidden();
});
