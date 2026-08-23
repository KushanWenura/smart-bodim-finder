<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiServiceClient
{
    private function request(string $path, array $payload = []): array
    {
        return Http::baseUrl(config('services.smart_bodim_ai.url'))
            ->timeout((float) config('services.smart_bodim_ai.timeout', 1.5))
            ->acceptJson()->withHeaders(['X-Internal-Secret' => config('services.smart_bodim_ai.secret'), 'X-Correlation-ID' => request()->header('X-Request-ID', (string) str()->uuid())])
            ->post($path, $payload)->throw()->json();
    }

    public function search(string $query, array $listings, int $limit = 50): array
    {
        try {
            return ['online' => true] + $this->request('/v1/search', compact('query', 'listings', 'limit'));
        } catch (\Throwable) {
            $terms = collect(preg_split('/\W+/', mb_strtolower($query)))->filter(fn ($term) => mb_strlen($term) > 2);
            $results = collect($listings)->map(function ($listing) use ($terms) {
                $haystack = mb_strtolower(implode(' ', [$listing['title'] ?? '', $listing['description'] ?? '', $listing['area'] ?? '', $listing['city'] ?? '', implode(' ', $listing['facilities'] ?? [])]));
                $score = $terms->sum(fn ($term) => str_contains($haystack, $term) ? 1 : 0) / max($terms->count(), 1);

                return ['id' => $listing['id'], 'score' => $score];
            })->filter(fn ($row) => $row['score'] > 0)->sortByDesc('score')->values()->all();

            return ['online' => false, 'mode' => 'keyword-fallback', 'warning' => 'AI search is temporarily unavailable. Structured filters remain active.', 'results' => $results];
        }
    }

    public function summarize(array $reviews): array
    {
        try {
            return ['online' => true] + $this->request('/v1/reviews/summarize', ['reviews' => $reviews]);
        } catch (\Throwable) {
            return ['online' => false, 'sampleSize' => count($reviews), 'summary' => count($reviews) < 2 ? 'Not enough reviews for a reliable summary.' : 'AI summary is temporarily unavailable. Read the individual reviews below.'];
        }
    }

    public function indexUpsert(int $id, string $text): array
    {
        return $this->request('/v1/index/upsert', compact('id', 'text'));
    }

    public function indexDelete(int $id): array
    {
        return $this->request('/v1/index/delete', compact('id'));
    }

    public function health(): array
    {
        try {
            return ['online' => true] + Http::baseUrl(config('services.smart_bodim_ai.url'))->timeout(1)->get('/health')->throw()->json();
        } catch (\Throwable) {
            return ['online' => false, 'service' => 'offline', 'modelReady' => false, 'indexReady' => false];
        }
    }
}
