<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\Listing;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProximityService
{
    private const ALIASES = [
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
        return Institution::query()->where('active', true)->orderBy('type')->orderBy('name')->get();
    }

    public function resolve(string $text): ?Institution
    {
        $normalized = Str::of($text)->lower()->squish()->toString();
        foreach ($this->destinations() as $destination) {
            $needles = array_merge([$destination->name], self::ALIASES[$destination->name] ?? []);
            foreach ($needles as $needle) {
                if (str_contains($normalized, Str::lower($needle))) {
                    return $destination;
                }
            }
        }

        return null;
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
}
