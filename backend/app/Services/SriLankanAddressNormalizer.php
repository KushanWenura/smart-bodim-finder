<?php

namespace App\Services;

use App\Models\Institution;
use Illuminate\Support\Str;

class SriLankanAddressNormalizer
{
    private ?array $places = null;

    public function resolve(string $query): ?array
    {
        $needle = $this->normalize($query);
        if ($needle === '') {
            return null;
        }

        $candidates = collect($this->places())->map(function (array $place) use ($needle): array {
            $terms = collect([$place['area'], $place['city'], ...($place['aliases'] ?? [])])->filter()->unique();
            $score = $terms->map(function (string $term) use ($needle): int {
                $term = $this->normalize($term);

                return match (true) {
                    $needle === $term => 100,
                    mb_strlen($term) >= 4 && str_contains($needle, $term) => 85,
                    mb_strlen($needle) >= 4 && str_contains($term, $needle) => 70,
                    default => 0,
                };
            })->max() ?? 0;

            return $place + ['score' => $score, 'source' => 'locality-catalog'];
        })->filter(fn (array $item) => $item['score'] > 0);

        $institution = Institution::query()->where('active', true)->get()->map(function (Institution $place) use ($needle): array {
            $terms = collect([$place->name, $place->organization_name, $place->branch_name, ...($place->aliases ?? [])])->filter()->unique();
            $score = $terms->map(function (string $term) use ($needle): int {
                $term = $this->normalize($term);

                return $needle === $term ? 98 : (mb_strlen($term) >= 4 && str_contains($needle, $term) ? 82 : 0);
            })->max() ?? 0;

            return ['area' => $place->branch_name ?: $place->name, 'city' => $place->branch_name ?: $place->name, 'district' => null, 'latitude' => (float) $place->latitude, 'longitude' => (float) $place->longitude, 'score' => $score, 'source' => 'institution-catalog', 'destination' => $place->name];
        })->filter(fn (array $item) => $item['score'] > 0);

        $match = $candidates->merge($institution)->sortByDesc('score')->first();
        if (! $match) {
            return null;
        }

        return $match + ['confidence' => $match['score'] >= 95 ? 'high' : ($match['score'] >= 80 ? 'medium' : 'low')];
    }

    public function canonicalize(string $area, string $city, string $district): array
    {
        $match = $this->resolve(trim("{$area} {$city}")) ?? $this->resolve($area);
        if (! $match || $match['source'] !== 'locality-catalog') {
            return ['area' => Str::title(trim($area)), 'city' => Str::title(trim($city)), 'district' => Str::title(trim($district)), 'match' => $match];
        }

        return ['area' => $match['area'], 'city' => $match['city'], 'district' => $match['district'], 'match' => $match];
    }

    public function suggestions(string $query, int $limit = 8): array
    {
        $needle = $this->normalize($query);

        return collect($this->places())->filter(function (array $place) use ($needle): bool {
            return $needle === '' || collect([$place['area'], $place['city'], ...($place['aliases'] ?? [])])->contains(fn ($term) => str_contains($this->normalize((string) $term), $needle));
        })->take($limit)->map(fn (array $place) => collect($place)->only(['area', 'city', 'district', 'latitude', 'longitude'])->all())->values()->all();
    }

    private function places(): array
    {
        if ($this->places !== null) {
            return $this->places;
        }
        $path = base_path('../datasets/catalog/sri_lanka_place_aliases.json');
        $catalog = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return $this->places = $catalog['places'] ?? [];
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)));
    }
}
