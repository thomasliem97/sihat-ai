<?php

namespace App\Support;

final class MedgemmaDraft
{
    /**
     * Keep MedGemma prose intact. Split FINDINGS / IMPRESSION headings only.
     *
     * @return array{findings: string, impression: string}
     */
    public static function split(string $draft): array
    {
        $text = trim($draft);
        if ($text === '') {
            return ['findings' => '', 'impression' => ''];
        }

        $withoutFindingsHeading = preg_replace('/^(?:\s*findings\s*:?\s*)+/iu', '', $text) ?? $text;
        $parts = preg_split('/\n\s*impression\s*:?\s*/iu', $withoutFindingsHeading, 2);

        if (is_array($parts) && count($parts) === 2) {
            return [
                'findings' => trim($parts[0]),
                'impression' => trim($parts[1]),
            ];
        }

        if (preg_match('/^\s*impression\s*:?\s*(.+)$/isu', $text, $match) === 1
            && preg_match('/^\s*findings\s*:/iu', $text) !== 1) {
            return ['findings' => '', 'impression' => trim($match[1])];
        }

        return ['findings' => $text, 'impression' => ''];
    }

    public static function isPromptEcho(string $text): bool
    {
        $sample = mb_strtolower($text);
        $needles = [
            'do not collapse this into a short list',
            'analyze the attached image(s) now',
            'do not omit findings or impression',
            'systematic review of every region in view',
            'the overall clinical interpretation and next step',
        ];

        foreach ($needles as $needle) {
            if (str_contains($sample, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop decode junk: dash/hash padding, a second FINDINGS block, leaked special tokens,
     * and numbered IMPRESSION items that start repeating.
     */
    public static function cleanLab(string $draft): string
    {
        $text = preg_replace('/<unused\d+>/i', '', $draft) ?? $draft;
        $parts = preg_split('/\bfinal report end\b/i', $text, 2);
        $text = is_array($parts) ? $parts[0] : $text;

        if (preg_match_all('/^\s*FINDINGS\s*:/im', $text, $matches, PREG_OFFSET_CAPTURE) >= 2) {
            $text = substr($text, 0, (int) $matches[0][1][1]);
        }

        $kept = [];
        $prev = null;
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $stripped = trim($line);
            if ($stripped !== '' && preg_match('/^[\s.\-#,;:_=\/\\\\*~]+$/', $stripped) === 1) {
                continue;
            }
            if ($stripped === $prev) {
                continue;
            }
            $kept[] = rtrim($line);
            $prev = $stripped;
        }

        return self::cutRepeatedNumberedItems(trim(implode("\n", $kept)));
    }

    /**
     * @param  array<string, mixed>|null  $report
     * @return array<string, mixed>|null
     */
    public static function scrubReport(?array $report): ?array
    {
        if ($report === null) {
            return null;
        }

        foreach (['findings_narrative', 'impression', 'summary', 'medgemma_draft'] as $key) {
            $value = $report[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $report[$key] = self::cleanLab($value);
            }
        }

        $draft = $report['medgemma_draft'] ?? null;
        if (is_string($draft) && $draft !== '') {
            $split = self::split($draft);
            if ($split['findings'] !== '' || $split['impression'] !== '') {
                $report['findings_narrative'] = $split['findings'];
                $report['impression'] = $split['impression'];
            }
        }

        foreach (['findings_narrative', 'impression', 'summary', 'medgemma_draft'] as $key) {
            $value = $report[$key] ?? null;
            if (is_string($value) && self::isPromptEcho($value)) {
                $report[$key] = '';
            }
        }

        return $report;
    }

    private static function numberedItemBody(string $line): string
    {
        if (preg_match('/^\s*\d+[.)]\s+(.+)$/', trim($line), $match) !== 1) {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($match[1])));

        return is_string($normalized) ? $normalized : '';
    }

    private static function cutRepeatedNumberedItems(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $inImpression = preg_match('/^\s*impression\s*:/im', $text) !== 1;
        $seen = [];
        $kept = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            if (preg_match('/^(\s*impression\s*:\s*)(.*)$/iu', $line, $heading) === 1) {
                $inImpression = true;
                $seen = [];
                $kept[] = rtrim($line);
                $rest = trim($heading[2]);
                if ($rest !== '') {
                    $body = self::numberedItemBody($rest);
                    if ($body !== '') {
                        $seen[$body] = true;
                    }
                }

                continue;
            }

            if ($inImpression) {
                $body = self::numberedItemBody($line);
                if ($body !== '') {
                    if (isset($seen[$body])) {
                        break;
                    }
                    $seen[$body] = true;
                }
            }

            $kept[] = rtrim($line);
        }

        return trim(implode("\n", $kept));
    }
}
