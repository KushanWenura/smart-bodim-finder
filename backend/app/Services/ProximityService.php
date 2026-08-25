<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\Listing;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProximityService
{
    private const LEGACY_ALIASES = [
        'University of Moratuwa' => ['uom', 'moratuwa university', 'katubedda campus'],
        'University of Colombo' => ['uoc', 'colombo university'],
        'University of Sri Jayewardenepura' => ['usj', 'jayewardenepura university', 'japura university'],
        'SLIIT Malabe Campus' => ['sliit', 'sliit malabe'],
        'NSBM Green University' => ['nsbm', 'nsbm homagama'],
        'University of Kelaniya' => ['uok', 'kelaniya university'],
        'University of Peradeniya' => ['uop', 'peradeniya university'],
        'University of Ruhuna' => ['uor', 'ruhuna university'],
        'University of Jaffna' => ['uofj', 'jaffna university'],
        'Kotelawala Defence University' => ['kdu', 'kotelawala university'],
        'World Trade Center Colombo' => ['wtc', 'world trade centre', 'fort office'],
        'Orion City IT Park' => ['orion city', 'dematagoda it park'],
        'TRACE Expert City' => ['trace city', 'maradana tech hub'],
        'Kandy City Centre' => ['kcc', 'kandy office'],
        'Galle City Centre' => ['galle office', 'galle town workplace'],
    ];

    public function destinations(): Collection
    {
        return Institution::query()->where('active', true)->orderBy('type')->orderBy('organization_name')->orderBy('branch_name')->orderBy('name')->get();
    }

    public function resolve(string $text): ?Institution
    {
        $resolution = $this->resolution($text);

        return $resolution['status'] === 'matched' ? $resolution['destination'] : null;
    }

    /**
     * Resolve a destination without silently choosing the wrong branch.
     *
     * @return array{status:string,destination:?Institution,organization:?string,suggestions:Collection}
     */
    public function resolution(string $text): array
    {
        $normalized = $this->normalize($text);
        $matches = $this->destinations()->map(function (Institution $destination) use ($normalized): ?array {
            $needles = collect([$destination->name, $destination->organization_name])
                ->merge($destination->aliases ?? [])
                ->merge(self::LEGACY_ALIASES[$destination->name] ?? [])
                ->filter()
                ->map(fn ($needle) => $this->normalize((string) $needle))
                ->filter()
                ->unique();
            $score = (int) ($needles->filter(fn ($needle) => str_contains($normalized, $needle))->map(fn ($needle) => mb_strlen($needle))->max() ?? 0);
            if ($score === 0) {
                return null;
            }
            $branch = $this->normalize((string) $destination->branch_name);
            if ($branch !== '' && str_contains($normalized, $branch)) {
                $score += 1000;
            }

            return ['destination' => $destination, 'score' => $score];
        })->filter()->values();

        if ($matches->isEmpty()) {
            return ['status' => 'not_found', 'destination' => null, 'organization' => null, 'suggestions' => collect()];
        }

        $highest = $matches->max('score');
        $leaders = $matches->where('score', $highest)->pluck('destination')->sortBy('name')->values();
        if ($leaders->count() === 1) {
            return ['status' => 'matched', 'destination' => $leaders->first(), 'organization' => $leaders->first()->organization_name, 'suggestions' => collect()];
        }

        $organizations = $leaders->pluck('organization_name')->filter()->unique()->values();

        return [
            'status' => 'ambiguous',
            'destination' => null,
            'organization' => $organizations->count() === 1 ? $organizations->first() : null,
            'suggestions' => $leaders,
        ];
    }

    public function annotate(Collection $listings, Institution $destination): Collection
    {
        return $listings->map(function (Listing $listing) use ($destination): Listing {
            $distance = $this->distanceKm((float) $listing->latitude, (float) $listing->longitude, $destination->latitude, $destination->longitude);
            $listing->setAttribute('distance_km', round($distance, 2));
            $listing->setAttribute('destination_name', $destination->name);
            $listing->setAttribute('commute_estimate_minutes', max(4, (int) ceil(($distance / 22) * 60)));

            return $listing;
        })->sortBy('distance_km')->values();
    }

    public function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0088;
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^\pL\pN]+/u', ' ', Str::lower(Str::ascii($value))));
    }
}
