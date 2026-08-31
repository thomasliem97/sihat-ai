<?php

namespace App\Support;

use App\Models\GuidelineChunk;
use App\Services\RagService;
use Illuminate\Support\Facades\File;

class GuidelineIngestor
{
    private const MAX_CHARS = 1100;

    private const MIN_CHARS = 60;

    public function __construct(private RagService $rag) {}

    /**
     * Replace guideline_chunks with official QR text from disk.
     *
     * @return int Number of chunks stored
     */
    public function ingest(?string $directory = null): int
    {
        $directory ??= storage_path('guidelines/text');

        if (! is_dir($directory)) {
            return 0;
        }

        $files = collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file): bool => strtolower($file->getExtension()) === 'txt'
                && preg_match('/^(e-)?QR|^20151019QR/i', $file->getFilename()) === 1)
            ->values();

        if ($files->isEmpty()) {
            return 0;
        }

        GuidelineChunk::query()->delete();

        $count = 0;
        foreach ($files as $file) {
            $text = File::get($file->getPathname());
            if (trim($text) === '') {
                continue;
            }

            $source = $this->sourceFromFilename($file->getFilename());
            $rows = [];
            foreach ($this->chunks($text) as $content) {
                $rows[] = [
                    'source' => $source,
                    'section' => $this->sectionFor($content),
                    'content' => $content,
                ];
            }
            $count += $this->persist($rows);
        }

        return $count;
    }

    /**
     * @param  list<array{source: string, section: string, content: string}>  $rows
     */
    private function persist(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = now();
        foreach (array_chunk($rows, 32) as $batch) {
            $texts = array_map(
                fn (array $row): string => $row['source'].' '.$row['section'].' '.$row['content'],
                $batch,
            );
            $embeddings = $this->rag->embedMany($texts);
            $payload = [];
            foreach ($batch as $i => $row) {
                $vector = $embeddings[$i] ?? null;
                $payload[] = [
                    'source' => $row['source'],
                    'section' => $row['section'],
                    'content' => $row['content'],
                    'embedding' => is_array($vector) && $vector !== [] ? json_encode($vector) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            GuidelineChunk::insert($payload);
        }

        return count($rows);
    }

    /**
     * @return list<string>
     */
    public function chunks(string $text): array
    {
        $normalized = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $paras = preg_split("/\n{2,}/", $normalized) ?: [];

        $chunks = [];
        $buffer = '';
        foreach ($paras as $para) {
            $para = trim(preg_replace('/\s+/u', ' ', $para) ?? $para);
            if (mb_strlen($para) < self::MIN_CHARS) {
                continue;
            }
            if (str_contains($para, '/uni00')) {
                continue;
            }

            foreach ($this->splitToLimit($para) as $piece) {
                $combined = trim($buffer.' '.$piece);
                if ($buffer !== '' && mb_strlen($combined) > self::MAX_CHARS) {
                    $chunks[] = $buffer;
                    $buffer = $piece;
                } else {
                    $buffer = $combined;
                }
            }
        }

        if (mb_strlen($buffer) >= self::MIN_CHARS) {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitToLimit(string $text): array
    {
        $parts = [];
        while (mb_strlen($text) > self::MAX_CHARS) {
            $window = mb_substr($text, 0, self::MAX_CHARS);
            $break = mb_strrpos($window, ' ');
            $len = ($break !== false && $break >= self::MIN_CHARS) ? $break : self::MAX_CHARS;
            $parts[] = trim(mb_substr($text, 0, $len));
            $text = trim(mb_substr($text, $len));
        }

        if (mb_strlen($text) >= self::MIN_CHARS) {
            $parts[] = $text;
        } elseif ($text !== '' && $parts !== []) {
            $merged = $parts[array_key_last($parts)].' '.$text;
            if (mb_strlen($merged) <= self::MAX_CHARS) {
                $parts[array_key_last($parts)] = $merged;
            }
        }

        return $parts;
    }

    public function sourceFromFilename(string $filename): string
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $stem = preg_replace('/^\d{8}/', '', $stem) ?? $stem;
        $stem = preg_replace('/^(e-)?QR[_-]*/i', '', $stem) ?? $stem;
        $stem = str_replace(['_', '-'], ' ', $stem);
        $stem = trim(preg_replace('/\s+/', ' ', $stem) ?? $stem);

        return 'MOH QR - '.$stem;
    }

    private function sectionFor(string $chunk): string
    {
        if (preg_match('/key messages/i', mb_substr($chunk, 0, 280)) === 1) {
            return 'Key messages';
        }

        if (preg_match('/^(\d+\.\s+.{12,70})/u', $chunk, $match) === 1) {
            return mb_substr(trim($match[1]), 0, 80);
        }

        return 'Quick reference';
    }
}
