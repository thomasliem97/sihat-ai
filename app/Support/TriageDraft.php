<?php

namespace App\Support;

final class TriageDraft
{
    /**
     * @var list<string>
     */
    private const ECHO_MARKERS = [
        'write the next triage reply',
        'plain text only, not json',
        'stay in medical triage scope',
        'without answering the off-topic ask',
        'give a full clinical reply rather than one sentence',
        'recent dialog (up to last 10 messages',
        'prefer this for immediate continuity',
        'current user message:',
        'running conversation summary',
        'prior medical record context',
    ];

    /**
     * @var list<string>
     */
    private const HEDGE_MARKERS = [
        'i am an ai',
        "i'm an ai",
        'as an ai',
        'i am a large language model',
        'i am a language model',
        'not a medical professional',
        'cannot replace clinical judgement',
        'cannot replace clinical judgment',
        'cannot provide medical advice',
        'not a substitute for professional medical',
        'consult a licensed clinician',
        'consult a medical professional',
        'this is decision support only',
        'confirm with a licensed clinician',
        'contact a healthcare provider',
        'contact a health care provider',
        'seek medical advice',
        'seek medical attention',
        'consult a doctor',
        'see a doctor',
        'see your doctor',
        'for further evaluation',
    ];

    /**
     * @var list<string>
     */
    private const PLAN_MARKERS = [
        'paracetamol',
        'acetaminophen',
        'ibuprofen',
        'panadol',
        ' mg',
        'lozenge',
        'oral rehydration',
        'fluids',
    ];

    /**
     * @var list<string>
     */
    private const VACUOUS_MARKERS = [
        'contact a healthcare',
        'healthcare provider',
        'health care provider',
        'further evaluation',
        'seek medical',
        'see a doctor',
        'see your doctor',
        'monitor your symptoms',
    ];

    public static function isPromptEcho(string $text): bool
    {
        $sample = mb_strtolower($text);

        foreach (self::ECHO_MARKERS as $marker) {
            if (str_contains($sample, $marker)) {
                return true;
            }
        }

        return false;
    }

    public static function scrub(string $text, string $userMessage = ''): string
    {
        $raw = trim($text);
        if ($raw === '' || self::isPromptEcho($raw)) {
            return '';
        }

        $beforeHedges = $raw;
        $raw = self::stripHedges($raw);
        $raw = self::stripLeadingAnswerEcho($raw, $userMessage);
        $raw = trim($raw);

        if ($raw !== '' && self::isVacuousPlan($raw)) {
            return '';
        }

        if (
            $raw !== ''
            && $raw !== trim($beforeHedges)
            && ! self::hasPlan($raw)
            && ! str_contains($raw, '?')
            && mb_strlen($raw) < 200
        ) {
            return '';
        }

        return $raw;
    }

    public static function pickReply(string $draft, string $structured, string $userMessage = ''): string
    {
        $cleanDraft = self::scrub($draft, $userMessage);
        $cleanStructured = self::scrub($structured, $userMessage);

        if ($cleanDraft === '') {
            return $cleanStructured;
        }

        if ($cleanStructured !== '' && ! self::hasPlan($cleanDraft) && self::hasPlan($cleanStructured)) {
            return $cleanStructured;
        }

        return $cleanDraft;
    }

    private static function stripHedges(string $text): string
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (trim($line) === '') {
                $lines[] = '';

                continue;
            }

            $kept = [];
            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $part) {
                if (trim($part) !== '' && ! self::isHedge($part)) {
                    $kept[] = $part;
                }
            }

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        return trim(implode("\n", $lines));
    }

    private static function isHedge(string $sentence): bool
    {
        $sample = mb_strtolower($sentence);

        foreach (self::HEDGE_MARKERS as $marker) {
            if (str_contains($sample, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function isVacuousPlan(string $text): bool
    {
        if (self::hasPlan($text)) {
            return false;
        }

        $sample = mb_strtolower($text);

        foreach (self::VACUOUS_MARKERS as $marker) {
            if (str_contains($sample, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function hasPlan(string $text): bool
    {
        $sample = mb_strtolower($text);

        foreach (self::PLAN_MARKERS as $marker) {
            if (str_contains($sample, $marker)) {
                return true;
            }
        }

        return preg_match('/\brest\b/u', $sample) === 1;
    }

    private static function stripLeadingAnswerEcho(string $text, string $userMessage): string
    {
        $answers = [];
        foreach (preg_split('/\R/u', $userMessage) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $answers[mb_strtolower($line)] = true;
            $pos = mb_strpos($line, ': ');
            if ($pos !== false) {
                $answers[mb_strtolower(trim(mb_substr($line, $pos + 2)))] = true;
            }
        }

        if ($answers === []) {
            return $text;
        }

        $rows = preg_split('/\R/u', $text) ?: [];
        $i = 0;
        while ($i < count($rows) && isset($answers[mb_strtolower(trim($rows[$i]))])) {
            $i++;
        }

        $rest = trim(implode("\n", array_slice($rows, $i)));

        return $rest !== '' ? $rest : $text;
    }
}
