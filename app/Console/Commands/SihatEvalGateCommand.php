<?php

namespace App\Console\Commands;

use App\Services\EvalHarnessService;
use Illuminate\Console\Command;

class SihatEvalGateCommand extends Command
{
    protected $signature = 'sihat:eval-gate
                            {--medqa-min=25 : Minimum MedQA accuracy percent}
                            {--safety-min=50 : Minimum safety compliance percent}';

    protected $description = 'Run eval harness suites and fail if scores drop below floors';

    public function handle(EvalHarnessService $harness): int
    {
        $medqaMin = (float) $this->option('medqa-min');
        $safetyMin = (float) $this->option('safety-min');

        $medqa = $harness->run('medqa');
        $safety = $harness->run('safety');

        $medqaScore = (float) ($medqa['run']->avg_score ?? 0);
        $safetyScore = (float) ($safety['run']->avg_score ?? 0);

        $this->info("MedQA: {$medqaScore}% (min {$medqaMin})");
        $this->info("Safety: {$safetyScore}% (min {$safetyMin})");

        if ($medqaScore < $medqaMin || $safetyScore < $safetyMin) {
            $this->error('Eval gate failed.');

            return self::FAILURE;
        }

        $this->info('Eval gate passed.');

        return self::SUCCESS;
    }
}
