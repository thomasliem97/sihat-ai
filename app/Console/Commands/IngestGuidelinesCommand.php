<?php

namespace App\Console\Commands;

use App\Support\GuidelineIngestor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('guidelines:ingest {--path= : Directory of official MOH QR .txt files}')]
#[Description('Chunk official MOH QR texts into guideline_chunks')]
class IngestGuidelinesCommand extends Command
{
    public function handle(GuidelineIngestor $ingestor): int
    {
        $path = $this->option('path');
        $directory = is_string($path) && $path !== '' ? $path : null;
        $count = $ingestor->ingest($directory);

        $this->info("Ingested {$count} guideline chunks.");

        return self::SUCCESS;
    }
}
