<?php

namespace App\Services;

use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RagService
{
    private bool $lastRetrievalWeak = false;

    private const MMR_LAMBDA = 0.7;

    private const TOP_K = 3;

    private const CANDIDATE_K = 8;

    /**
     * Hybrid dense + BM25 retrieval with MMR rerank.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    public function retrieveCitations(MedicalRecord $record, array $findings): array
    {
        $this->lastRetrievalWeak = false;

        $queryParts = collect($findings)->pluck('label')->filter()->all();
        $prior = MedicalRecord::query()
            ->where('user_id', $record->user_id)
            ->where('id', '!=', $record->id)
            ->whereNotNull('findings')
            ->latest()
            ->limit(3)
            ->get()
            ->flatMap(fn (MedicalRecord $prior) => collect($prior->findings ?? [])->pluck('label'))
            ->filter()
            ->all();

        $query = implode(' ', array_merge($queryParts, $prior, [
            $record->detected_modality?->label() ?? $record->modality->label(),
        ]));

        $chunks = GuidelineChunk::query()->get();

        if ($chunks->isEmpty()) {
            $this->lastRetrievalWeak = true;

            return [];
        }

        $queryEmbedding = $this->embed($query);
        $this->ensureChunkEmbeddings($chunks);

        $dense = $this->denseCandidates($chunks, $queryEmbedding, $query);
        $bm25 = $this->bm25Candidates($chunks, $query);
        $fused = $this->fuseCandidates($dense, $bm25);
        $reranked = $this->mmrRerank($fused, $queryEmbedding, self::TOP_K);

        $top = (float) ($reranked[0]['relevance'] ?? 0);
        // Hash embeddings are coarse; BM25 carries most offline signal.
        $this->lastRetrievalWeak = $reranked === [] || $top < 0.2;

        // Weak retrieve: empty citations (no static stubs in live path)
        if ($this->lastRetrievalWeak) {
            return [];
        }

        return $reranked;
    }

    public function wasWeakRetrieval(array $citations = []): bool
    {
        return $this->lastRetrievalWeak;
    }

    /**
     * Static stubs kept for tests / offline demos only. Not used by retrieveCitations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function defaultCitations(): array
    {
        return [
            [
                'source' => 'MOH Malaysia CPG - Community Acquired Pneumonia',
                'section' => '4.2 Diagnosis',
                'excerpt' => 'Chest radiograph may show lobar or patchy consolidation. Clinical correlation is essential.',
                'relevance' => 0.4,
            ],
            [
                'source' => 'MOH Malaysia CPG - Tuberculosis',
                'section' => '3.1 Imaging',
                'excerpt' => 'Upper lobe cavitary lesions are characteristic but lower lobe involvement can occur.',
                'relevance' => 0.35,
            ],
        ];
    }

    /**
     * @return array<int, float>|null
     */
    public function embed(string $text): ?array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            return $this->localHashEmbed($text);
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => config('services.openai.embedding_model', 'text-embedding-3-small'),
                    'input' => mb_substr($text, 0, 8000),
                ]);

            if (! $response->successful()) {
                Log::warning('OpenAI embedding failed', ['status' => $response->status()]);

                return $this->localHashEmbed($text);
            }

            /** @var array<int, float> $embedding */
            $embedding = $response->json('data.0.embedding') ?? [];

            return $embedding !== [] ? $embedding : $this->localHashEmbed($text);
        } catch (\Throwable $e) {
            Log::warning('OpenAI embedding error', ['error' => $e->getMessage()]);

            return $this->localHashEmbed($text);
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
     * @param  Collection<int, GuidelineChunk>  $chunks
     */
    private function ensureChunkEmbeddings($chunks): void
    {
        foreach ($chunks as $chunk) {
            if (is_array($chunk->embedding) && $chunk->embedding !== []) {
                continue;
            }

            $embedding = $this->embed($chunk->source.' '.$chunk->section.' '.$chunk->content);
            if ($embedding !== null) {
                $chunk->update(['embedding' => $embedding]);
            }
        }
    }

    /**
     * @param  Collection<int, GuidelineChunk>  $chunks
     * @param  array<int, float>|null  $queryEmbedding
     * @return array<int, array<string, mixed>>
     */
    private function denseCandidates($chunks, ?array $queryEmbedding, string $query): array
    {
        if ($queryEmbedding === null) {
            return [];
        }

        return $chunks->map(function (GuidelineChunk $chunk) use ($queryEmbedding, $query) {
            $embedding = $chunk->embedding;
            $score = is_array($embedding) && $embedding !== []
                ? $this->cosineSimilarity($queryEmbedding, $embedding)
                : 0.0;

            return $this->citationRow($chunk, $score, $query, $embedding);
        })
            ->sortByDesc('relevance')
            ->take(self::CANDIDATE_K)
            ->values()
            ->all();
    }

    /**
     * In-PHP BM25 over guideline chunks (no external deps).
     *
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

            // Scale BM25 into ~0..1; offline hash-dense is weak so BM25 must carry the fuse.
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
            $key = ($row['source'] ?? '').'|'.($row['section'] ?? '');
            if (! isset($byKey[$key]) || ($row['relevance'] ?? 0) > ($byKey[$key]['relevance'] ?? 0)) {
                $byKey[$key] = $row;
            } else {
                // Boost when both retrievers hit
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

        // Fallback: lexical overlap of excerpts
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
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }

        $denom = sqrt($na) * sqrt($nb);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
