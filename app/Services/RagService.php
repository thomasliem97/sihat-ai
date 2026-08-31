<?php

namespace App\Services;

use App\Enums\Modality;
use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RagService
{
    private bool $lastRetrievalWeak = false;

    private const MMR_LAMBDA = 0.7;

    private const TOP_K = 5;

    private const CANDIDATE_K = 8;

    /**
     * Hybrid dense + BM25 retrieval with MMR rerank.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, string>  $extraTerms
     * @return array<int, array<string, mixed>>
     */
    public function retrieveCitations(MedicalRecord $record, array $findings, array $extraTerms = []): array
    {
        $this->lastRetrievalWeak = false;

        $modality = $record->detected_modality ?? $record->modality;
        $isLab = $modality === Modality::LabPdf;

        $queryParts = $isLab
            ? []
            : collect($findings)
                ->filter(fn (array $finding): bool => self::isRagFinding($finding))
                ->flatMap(fn (array $finding): array => [
                    $finding['label'] ?? null,
                    $finding['description'] ?? null,
                ])
                ->filter()
                ->all();
        $report = $record->physician_report ?? [];
        $ddx = $report['differential_diagnosis'] ?? [];
        $fromReport = collect(is_array($ddx) ? $ddx : [])
            ->map(fn (mixed $row): string => is_array($row) ? (string) ($row['condition'] ?? '') : '')
            ->filter()
            ->all();

        $extraTerms = array_values(array_filter(
            $extraTerms,
            fn (mixed $term): bool => self::hasEnoughTokens($term),
        ));
        $fromReport = array_values(array_filter(
            $fromReport,
            fn (string $term): bool => self::hasEnoughTokens($term),
        ));

        $query = collect(array_merge(
            $queryParts,
            $extraTerms,
            $fromReport,
        ))
            ->flatMap(fn (mixed $part): array => preg_split('/\W+/u', mb_strtolower((string) $part), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->unique()
            ->reject(fn (string $token): bool => self::isWeakQueryToken($token))
            ->implode(' ');

        if (trim($query) === '') {
            $this->lastRetrievalWeak = true;

            return [];
        }

        if (! GuidelineChunk::query()->exists()) {
            $this->lastRetrievalWeak = true;

            return [];
        }

        $openAiEmbedding = $this->embed($query);
        if ($openAiEmbedding !== null) {
            $this->backfillMissingEmbeddings($openAiEmbedding);
        }

        $chunks = GuidelineChunk::query()
            ->select(['id', 'source', 'section', 'content'])
            ->get();

        $dense = $openAiEmbedding === null
            ? []
            : $this->denseCandidates($openAiEmbedding, $query);
        $bm25 = $this->bm25Candidates($chunks, $query);
        $fused = array_values(array_filter(
            $this->fuseCandidates($dense, $bm25),
            fn (array $row): bool => (float) ($row['relevance'] ?? 0) >= 0.2,
        ));
        $reranked = $this->mmrRerank($fused, $openAiEmbedding, self::TOP_K);

        $top = (float) ($reranked[0]['relevance'] ?? 0);
        $this->lastRetrievalWeak = $reranked === [] || $top < 0.2;

        if ($this->lastRetrievalWeak) {
            return [];
        }

        return $reranked;
    }

    /**
     * @param  array<int, array<string, mixed>>  $citations
     */
    public function wasWeakRetrieval(array $citations = []): bool
    {
        return $this->lastRetrievalWeak;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private static function isRagFinding(array $finding): bool
    {
        $severity = strtolower((string) ($finding['severity'] ?? $finding['status'] ?? ''));
        if ($severity === 'normal') {
            return false;
        }

        $label = strtolower((string) ($finding['label'] ?? ''));

        return ! str_contains($label, 'technical quality');
    }

    private static function hasEnoughTokens(mixed $term): bool
    {
        $words = preg_split('/\W+/u', (string) $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) >= 2;
    }

    private static function isWeakQueryToken(string $token): bool
    {
        if (mb_strlen($token) < 3) {
            return true;
        }

        return in_array($token, [
            'and', 'are', 'for', 'the', 'this', 'that', 'with', 'from',
            'there', 'then', 'than', 'into', 'over', 'under', 'upon',
        ], true);
    }

    /**
     * @return array<int, float>|null
     */
    public function embed(string $text): ?array
    {
        return $this->embedMany([$text])[0] ?? null;
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>|null>
     */
    public function embedMany(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            return array_fill(0, count($texts), null);
        }

        $out = [];
        foreach (array_chunk(array_values($texts), 64) as $batch) {
            array_push($out, ...$this->embedBatch($batch, (string) $apiKey));
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $batch
     * @return array<int, array<int, float>|null>
     */
    private function embedBatch(array $batch, string $apiKey): array
    {
        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => config('services.openai.embedding_model', 'text-embedding-3-small'),
                    'input' => array_map(fn (string $text): string => mb_substr($text, 0, 8000), $batch),
                ]);

            if (! $response->successful()) {
                Log::warning('OpenAI embedding failed', ['status' => $response->status()]);

                return array_fill(0, count($batch), null);
            }

            $byIndex = [];
            foreach ($response->json('data') ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $index = (int) ($row['index'] ?? count($byIndex));
                $embedding = $row['embedding'] ?? [];
                $byIndex[$index] = is_array($embedding) && $embedding !== [] ? array_values($embedding) : null;
            }

            $out = [];
            foreach (array_keys($batch) as $i) {
                $out[] = $byIndex[$i] ?? null;
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('OpenAI embedding error', ['error' => $e->getMessage()]);

            return array_fill(0, count($batch), null);
        }
    }

    /**
     * @return array<int, float>
     */
    public function localHashEmbed(string $text): array
    {
        $dim = 64;
        $vec = array_fill(0, $dim, 0.0);
        $tokens = preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $idx = crc32($token) % $dim;
            $vec[$idx] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vec))) ?: 1.0;

        return array_map(fn (float $v) => $v / $norm, $vec);
    }

    /**
     * @param  array<int, float>  $queryEmbedding
     */
    private function backfillMissingEmbeddings(array $queryEmbedding): void
    {
        GuidelineChunk::query()
            ->select(['id', 'source', 'section', 'content', 'embedding'])
            ->orderBy('id')
            ->chunkById(32, function ($batch) use ($queryEmbedding): void {
                $stale = $batch->filter(
                    fn (GuidelineChunk $chunk): bool => ! $this->sameDimension($chunk->embedding, $queryEmbedding)
                );
                if ($stale->isEmpty()) {
                    return;
                }

                $texts = $stale
                    ->map(fn (GuidelineChunk $chunk): string => $chunk->source.' '.$chunk->section.' '.$chunk->content)
                    ->values()
                    ->all();
                $vectors = $this->embedMany($texts);

                foreach ($stale->values() as $i => $chunk) {
                    $vector = $vectors[$i] ?? null;
                    if (! is_array($vector) || $vector === []) {
                        continue;
                    }
                    $chunk->update(['embedding' => $vector]);
                }
            });
    }

    /**
     * @param  array<int, float>  $queryEmbedding
     */
    private function sameDimension(mixed $embedding, array $queryEmbedding): bool
    {
        return is_array($embedding) && $embedding !== [] && count($embedding) === count($queryEmbedding);
    }

    /**
     * @param  array<int, float>  $queryEmbedding
     * @return array<int, array<string, mixed>>
     */
    private function denseCandidates(array $queryEmbedding, string $query): array
    {
        $top = [];

        GuidelineChunk::query()
            ->select(['id', 'source', 'section', 'content', 'embedding'])
            ->orderBy('id')
            ->chunkById(32, function ($batch) use (&$top, $queryEmbedding, $query): void {
                foreach ($batch as $chunk) {
                    $embedding = $chunk->embedding;
                    $score = $this->sameDimension($embedding, $queryEmbedding)
                        ? $this->cosineSimilarity($queryEmbedding, $embedding)
                        : 0.0;

                    $top[] = $this->citationRow($chunk, $score, $query, is_array($embedding) ? $embedding : null);
                }

                usort($top, fn (array $a, array $b): int => ($b['relevance'] ?? 0) <=> ($a['relevance'] ?? 0));
                $top = array_slice($top, 0, self::CANDIDATE_K);
            });

        return $top;
    }

    /**
     * @param  Collection<int, GuidelineChunk>  $chunks
     * @return array<int, array<string, mixed>>
     */
    private function bm25Candidates($chunks, string $query): array
    {
        $terms = preg_split('/\W+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($terms === []) {
            return [];
        }

        $k1 = 1.2;
        $b = 0.75;
        $docs = $chunks->map(function (GuidelineChunk $chunk) {
            $tokens = preg_split('/\W+/u', mb_strtolower($chunk->source.' '.$chunk->section.' '.$chunk->content), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return ['chunk' => $chunk, 'tokens' => $tokens, 'len' => count($tokens)];
        });

        $avgdl = max(1.0, (float) $docs->avg('len'));
        $n = max(1, $docs->count());
        $df = [];
        foreach ($terms as $term) {
            $df[$term] = $docs->filter(fn ($d) => in_array($term, $d['tokens'], true))->count();
        }

        $scored = $docs->map(function (array $doc) use ($terms, $df, $n, $k1, $b, $avgdl, $query) {
            $tfMap = array_count_values($doc['tokens']);
            $score = 0.0;
            foreach ($terms as $term) {
                $tf = (int) ($tfMap[$term] ?? 0);
                if ($tf === 0) {
                    continue;
                }
                $idf = log(1 + ($n - $df[$term] + 0.5) / ($df[$term] + 0.5));
                $score += $idf * (($tf * ($k1 + 1)) / ($tf + $k1 * (1 - $b + $b * ($doc['len'] / $avgdl))));
            }

            $norm = min(1.0, $score / max(1.0, log(1 + count($terms)) * 2));

            return $this->citationRow($doc['chunk'], $norm, $query, $doc['chunk']->embedding);
        })
            ->sortByDesc('relevance')
            ->take(self::CANDIDATE_K)
            ->values()
            ->all();

        return $scored;
    }

    /**
     * @param  array<int, array<string, mixed>>  $dense
     * @param  array<int, array<string, mixed>>  $bm25
     * @return array<int, array<string, mixed>>
     */
    private function fuseCandidates(array $dense, array $bm25): array
    {
        $byKey = [];
        foreach (array_merge($dense, $bm25) as $row) {
            $key = (string) ($row['id'] ?? (($row['source'] ?? '').'|'.($row['section'] ?? '').'|'.($row['excerpt'] ?? '')));
            if (! isset($byKey[$key]) || ($row['relevance'] ?? 0) > ($byKey[$key]['relevance'] ?? 0)) {
                $byKey[$key] = $row;
            } else {
                $byKey[$key]['relevance'] = min(1.0, (float) $byKey[$key]['relevance'] + 0.05);
            }
        }

        return array_values($byKey);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, float>|null  $queryEmbedding
     * @return array<int, array<string, mixed>>
     */
    private function mmrRerank(array $candidates, ?array $queryEmbedding, int $k): array
    {
        if ($candidates === []) {
            return [];
        }

        usort($candidates, fn ($a, $b) => ($b['relevance'] ?? 0) <=> ($a['relevance'] ?? 0));

        $selected = [];
        $remaining = $candidates;

        while (count($selected) < $k && $remaining !== []) {
            $bestIdx = 0;
            $bestScore = -INF;

            foreach ($remaining as $i => $cand) {
                $rel = (float) ($cand['relevance'] ?? 0);
                $div = 0.0;
                if ($queryEmbedding !== null && $selected !== []) {
                    foreach ($selected as $sel) {
                        $div = max($div, $this->citationSimilarity($cand, $sel, $queryEmbedding));
                    }
                }
                $mmr = self::MMR_LAMBDA * $rel - (1 - self::MMR_LAMBDA) * $div;
                if ($mmr > $bestScore) {
                    $bestScore = $mmr;
                    $bestIdx = $i;
                }
            }

            $selected[] = $remaining[$bestIdx];
            array_splice($remaining, $bestIdx, 1);
        }

        return array_map(function (array $row) {
            unset($row['_embedding']);

            return $row;
        }, $selected);
    }

    /**
     * @param  array<int, float>|null  $embedding
     * @return array<string, mixed>
     */
    private function citationRow(GuidelineChunk $chunk, float $score, string $query, ?array $embedding): array
    {
        return [
            'id' => $chunk->id,
            'source' => $chunk->source,
            'section' => $chunk->section,
            'excerpt' => mb_substr($chunk->content, 0, 200).'…',
            'relevance' => round($score, 3),
            'query' => $query,
            '_embedding' => is_array($embedding) ? $embedding : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<int, float>  $queryEmbedding
     */
    private function citationSimilarity(array $a, array $b, array $queryEmbedding): float
    {
        $ea = $a['_embedding'] ?? null;
        $eb = $b['_embedding'] ?? null;
        if (is_array($ea) && is_array($eb) && $ea !== [] && $eb !== []) {
            return $this->cosineSimilarity($ea, $eb);
        }

        $ta = preg_split('/\W+/u', mb_strtolower((string) ($a['excerpt'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tb = preg_split('/\W+/u', mb_strtolower((string) ($b['excerpt'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($ta === [] || $tb === []) {
            return 0.0;
        }
        $overlap = count(array_intersect($ta, $tb));

        return $overlap / max(count($ta), count($tb));
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || count($a) === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }

        $denom = sqrt($na) * sqrt($nb);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
