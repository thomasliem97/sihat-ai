<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\MedicalRecord;

class SimilarCaseService
{
    /**
     * Drop neighbors below this cosine/text score so the UI only shows useful matches.
     */
    public const MIN_SCORE = 0.6;

    public function __construct(private RagService $rag) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function retrieve(MedicalRecord $record, int $limit = 5): array
    {
        $queryText = $this->embeddingText($record);
        $queryEmbedding = $record->findings_embedding;

        if (! is_array($queryEmbedding) || $queryEmbedding === []) {
            $queryEmbedding = $this->rag->embed($queryText) ?? [];
        }

        if ($queryEmbedding === []) {
            return [];
        }

        $candidates = MedicalRecord::query()
            ->where('status', RecordStatus::Completed)
            ->where('id', '!=', $record->id)
            ->whereNotNull('findings')
            ->latest('analyzed_at')
            ->limit(50)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $modality = ($record->detected_modality ?? $record->modality)->value;

        $scored = $candidates->map(function (MedicalRecord $candidate) use ($queryEmbedding, $modality) {
            $score = $this->score($queryEmbedding, $candidate);
            $candModality = ($candidate->detected_modality ?? $candidate->modality)->value;
            if ($candModality === $modality) {
                $score = min(1.0, $score + 0.05);
            }

            $candidateFindings = $candidate->findings ?? [];
            $preview = collect($candidateFindings)
                ->pluck('label')
                ->filter()
                ->take(3)
                ->implode(', ');

            return [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'modality' => $candModality,
                'modality_label' => ($candidate->detected_modality ?? $candidate->modality)->label(),
                'score' => round($score, 3),
                'findings_preview' => $preview !== '' ? $preview : 'No labeled findings',
                'analyzed_at' => $candidate->analyzed_at?->toIso8601String(),
            ];
        })
            ->filter(fn (array $row) => $row['score'] >= self::MIN_SCORE)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        return $scored;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, float>
     */
    public function embedResult(MedicalRecord $record, array $result): array
    {
        $findings = is_array($result['findings'] ?? null) ? array_values($result['findings']) : [];
        $labels = collect($findings)->pluck('label')->filter()->all();
        $modality = $result['detected_modality']
            ?? ($record->detected_modality ?? $record->modality)->value;
        $text = trim(implode(' ', [...$labels, (string) $modality]));

        return $this->rag->embed($text !== '' ? $text : $record->title) ?? [];
    }

    public function embeddingText(MedicalRecord $record): string
    {
        $findings = $record->findings ?? [];
        $labels = collect($findings)->pluck('label')->filter()->all();
        $modality = ($record->detected_modality ?? $record->modality)->value;

        return trim(implode(' ', [...$labels, $modality, $record->title]));
    }

    /**
     * @param  array<int, float>  $queryEmbedding
     */
    private function score(array $queryEmbedding, MedicalRecord $candidate): float
    {
        $candidateEmbedding = $candidate->findings_embedding;
        if (! is_array($candidateEmbedding) || $candidateEmbedding === [] || $queryEmbedding === []) {
            return 0.0;
        }

        return $this->rag->cosineSimilarity($queryEmbedding, $candidateEmbedding);
    }
}
