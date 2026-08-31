<?php

namespace App\Support;

use App\Models\MedicalRecord;
use App\Services\DeidentificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class LabTextExtractor
{
    public const MIN_CHARS = 40;

    public function __construct(private DeidentificationService $deidentification) {}

    public function extract(MedicalRecord $record): string
    {
        $relative = $record->inferenceFilePath();
        if ($relative === '' || ! Storage::disk('local')->exists($relative)) {
            return '';
        }

        return $this->extractFrom(
            Storage::disk('local')->path($relative),
            $record->mime_type,
            $record->original_filename,
        );
    }

    public function extractFrom(string $absolutePath, string $mime, string $filename): string
    {
        if (! is_file($absolutePath)) {
            return '';
        }

        $mime = strtolower($mime);
        $name = strtolower($filename);

        if (! str_contains($mime, 'pdf') && ! str_ends_with($name, '.pdf')) {
            return '';
        }

        $text = $this->pdftotext($absolutePath);
        $text = trim($text);
        if (mb_strlen($text) < self::MIN_CHARS) {
            return '';
        }

        return $this->deidentification->scrubText($text);
    }

    private function pdftotext(string $absolutePath): string
    {
        $binary = $this->binary();
        $result = Process::timeout(30)->run([
            $binary,
            '-table',
            '-enc',
            'UTF-8',
            '-nopgbrk',
            $absolutePath,
            '-',
        ]);

        if ($result->successful()) {
            return $result->output();
        }

        Log::info('pdftotext failed', [
            'binary' => $binary,
            'error' => mb_substr($result->errorOutput(), 0, 240),
        ]);

        return '';
    }

    private function binary(): string
    {
        $configured = trim((string) config('services.pdftotext.path', 'pdftotext'));
        $candidates = array_values(array_filter([
            $configured !== '' ? $configured : null,
            'pdftotext',
            'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
            '/usr/bin/pdftotext',
            '/opt/homebrew/bin/pdftotext',
        ]));

        foreach ($candidates as $candidate) {
            if ($candidate !== 'pdftotext' && is_file($candidate)) {
                return $candidate;
            }
        }

        return $configured !== '' ? $configured : 'pdftotext';
    }
}
