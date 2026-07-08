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
