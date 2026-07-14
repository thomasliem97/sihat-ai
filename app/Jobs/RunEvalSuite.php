<?php

namespace App\Jobs;

use App\Services\EvalHarnessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunEvalSuite implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $suite) {}

    public function handle(EvalHarnessService $harness): void
    {
        try {
            $harness->run($this->suite);
        } catch (\Throwable $e) {
            Log::error('Eval suite failed', [
                'suite' => $this->suite,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
