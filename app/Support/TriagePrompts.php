<?php

namespace App\Support;

final class TriagePrompts
{
    public const MAX_QUESTIONS = 3;

    public const MAX_OPTIONS = 4;

    /**
     * @return list<array{id: string, question: string, allow_multiple: bool, options: list<array{id: string, label: string}>}>
     */
    public static function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $prompts = [];
        $seenQuestions = [];

        foreach ($raw as $row) {
            if (count($prompts) >= self::MAX_QUESTIONS) {
                break;
            }

            if (! is_array($row)) {
                continue;
            }

            $question = self::clip((string) ($row['question'] ?? ''), 120);
            if (mb_strlen($question) < 4) {
                continue;
            }

            $fold = mb_strtolower($question);
            if (isset($seenQuestions[$fold])) {
                continue;
            }

            $options = self::normalizeOptions($row['options'] ?? null);
            if (count($options) < 2) {
                continue;
            }

            $seenQuestions[$fold] = true;
            $prompts[] = [
                'id' => self::uniqueId((string) ($row['id'] ?? ''), 'q', $prompts),
                'question' => $question,
                'allow_multiple' => (bool) ($row['allow_multiple'] ?? false),
                'options' => $options,
            ];
        }

        return $prompts;
    }

    /**
     * @param  list<array{id: string, question: string, allow_multiple: bool, options: list<array{id: string, label: string}>}>  $prompts
     * @param  array<string, list<string>>  $selections
     */
    public static function formatAnswers(array $prompts, array $selections): string
    {
        $lines = [];

        foreach ($prompts as $prompt) {
            $chosen = $selections[$prompt['id']] ?? [];
            if ($chosen === []) {
                continue;
            }

            $labels = [];
            foreach ($chosen as $optionId) {
                foreach ($prompt['options'] as $option) {
                    if ($option['id'] === $optionId) {
                        $labels[] = $option['label'];
                        break;
                    }
                }
            }

            if ($labels === []) {
                continue;
            }

            $lines[] = $prompt['question'].': '.implode(', ', $labels);
        }

        return implode("\n", $lines);
    }

    public static function looksLikeAnswers(string $text): bool
    {
        $lines = array_values(array_filter(
            preg_split('/\R/u', trim($text)) ?: [],
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === [] || count($lines) > self::MAX_QUESTIONS) {
            return false;
        }

        foreach ($lines as $line) {
            if (preg_match('/^.+: .+$/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private static function normalizeOptions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $options = [];

        foreach ($raw as $row) {
            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }

            if (! is_array($row)) {
                continue;
            }

            $label = self::clip((string) ($row['label'] ?? ''), 48);
            if ($label === '') {
                continue;
            }

            $options[] = [
                'id' => self::uniqueId((string) ($row['id'] ?? ''), 'o', $options),
                'label' => $label,
            ];
        }

        return $options;
    }

    /**
     * @param  list<array{id: string}>  $existing
     */
    private static function uniqueId(string $raw, string $prefix, array $existing): string
    {
        $id = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $raw) ?? '');
        $id = trim($id, '-');
        if ($id === '') {
            $id = $prefix.(count($existing) + 1);
        }

        $id = mb_substr($id, 0, 32);
        $used = array_column($existing, 'id');
        $candidate = $id;
        $n = 2;

        while (in_array($candidate, $used, true)) {
            $candidate = mb_substr($id, 0, 28).'-'.$n;
            $n++;
        }

        return $candidate;
    }

    private static function clip(string $value, int $max): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        return mb_substr($trimmed, 0, $max);
    }
}
