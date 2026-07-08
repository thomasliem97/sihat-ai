<?php

namespace App\Console\Commands;

use App\Services\EvalHarnessService;
use Illuminate\Console\Command;

class EvalRunCommand extends Command
{
    protected $signature = 'eval:run {suite : medqa|llm_judge|safety}';

    protected $description = 'Run a live SihatAI evaluation suite and store EvalRun metrics';

    public function handle(EvalHarnessService $harness): int
    {
        $suite = (string) $this->argument('suite');

        try {
            $result = $harness->run($suite);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $run = $result['run'];
        $this->info("Stored eval run #{$run->id} ({$run->run_type}) score={$run->avg_score}");

        return self::SUCCESS;
    }
}
