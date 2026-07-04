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

    /**
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

            return $this->defaultCitations();
        }

        $queryEmbedding = $this->embed($query);

        if ($queryEmbedding === null) {
            // ponytail: lexical fallback when no embedding API; upgrade by setting OPENAI_API_KEY
            return $this->lexicalRetrieve($chunks, $query);
        }

        $this->ensureChunkEmbeddings($chunks);

        $scored = $chunks->map(function (GuidelineChunk $chunk) use ($queryEmbedding, $query) {
            $embedding = $chunk->embedding;
            $score = is_array($embedding) && $embedding !== []
                ? $this->cosineSimilarity($queryEmbedding, $embedding)
                : 0.0;

            return [
                'source' => $chunk->source,
                'section' => $chunk->section,
                'excerpt' => mb_substr($chunk->content, 0, 200).'…',
                'relevance' => round($score, 3),
                'query' => $query,
            ];
        })
            ->sortByDesc('relevance')
            ->take(3)
            ->values();

        $top = (float) ($scored->first()['relevance'] ?? 0);
        $this->lastRetrievalWeak = $top < 0.35;

        if ($scored->isEmpty() || $this->lastRetrievalWeak) {
            return $this->defaultCitations();
        }

        return $scored->all();
    }

    public function wasWeakRetrieval(array $citations = []): bool
    {
        return $this->lastRetrievalWeak;
    }

    /**
     * Embed text; returns null when embeddings are unavailable.
     *
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
     * Deterministic bag-of-tokens embedding for offline MVP (no API key).
     *
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
     * @return array<int, array<string, mixed>>
     */
    private function lexicalRetrieve($chunks, string $query): array
    {
        $terms = preg_split('/\W+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $scored = $chunks->map(function (GuidelineChunk $chunk) use ($terms, $query) {
            $hay = mb_strtolower($chunk->source.' '.$chunk->section.' '.$chunk->content);
            $hits = 0;
            foreach ($terms as $term) {
                if ($term !== '' && str_contains($hay, $term)) {
                    $hits++;
                }
            }
            $score = count($terms) > 0 ? $hits / count($terms) : 0;

            return [
                'source' => $chunk->source,
                'section' => $chunk->section,
                'excerpt' => mb_substr($chunk->content, 0, 200).'…',
                'relevance' => round(min(1, $score + 0.2), 3),
                'query' => $query,
            ];
        })
            ->sortByDesc('relevance')
            ->take(3)
            ->values();

        $top = (float) ($scored->first()['relevance'] ?? 0);
        $this->lastRetrievalWeak = $top < 0.35;

        return $scored->all();
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultCitations(): array
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
}
