<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RouteEstimator
{
    private bool $remoteUnavailable = false;

    /**
     * Return transparent multimodal estimates. An OSRM-compatible service is
     * used when configured; otherwise conservative Sri Lankan urban estimates
     * are derived from straight-line distance and clearly labelled.
     */
    public function estimate(float $fromLat, float $fromLon, float $toLat, float $toLon, float $airDistanceKm): array
    {
        $road = $this->roadRoute($fromLat, $fromLon, $toLat, $toLon);
        $roadDistance = $road['distanceKm'] ?? round($airDistanceKm * $this->circuity($airDistanceKm), 2);
        $driveMinutes = $road['durationMinutes'] ?? max(4, (int) ceil(($roadDistance / 24) * 60));
        $walkDistance = round($airDistanceKm * 1.18, 2);

        return [
            'method' => $road ? 'OSRM road route with transparent local mode estimates' : 'Offline distance-derived estimate (not live traffic)',
            'provider' => $road ? 'osrm' : 'offline',
            'modes' => [
                'walking' => ['distanceKm' => $walkDistance, 'minutes' => max(5, (int) ceil(($walkDistance / 4.6) * 60))],
                'driving' => ['distanceKm' => $roadDistance, 'minutes' => $driveMinutes],
                'publicTransport' => ['distanceKm' => $roadDistance, 'minutes' => max(8, (int) ceil(($roadDistance / 19) * 60) + 8)],
            ],
            'recommendedMode' => $roadDistance <= 1.5 ? 'walking' : 'publicTransport',
            'geometry' => $road['geometry'] ?? [[$fromLat, $fromLon], [$toLat, $toLon]],
        ];
    }

    private function roadRoute(float $fromLat, float $fromLon, float $toLat, float $toLon): ?array
    {
        $baseUrl = rtrim((string) config('services.routing.url'), '/');
        if ($baseUrl === '' || $this->remoteUnavailable) {
            return null;
        }
        $key = 'route:'.hash('sha256', implode(',', array_map(fn ($value) => round($value, 5), [$fromLat, $fromLon, $toLat, $toLon])));

        return Cache::remember($key, now()->addDays(30), function () use ($baseUrl, $fromLat, $fromLon, $toLat, $toLon): ?array {
            try {
                $coordinates = $fromLon.','.$fromLat.';'.$toLon.','.$toLat;
                $response = Http::timeout((float) config('services.routing.timeout', 1.2))
                    ->acceptJson()
                    ->get($baseUrl.'/route/v1/driving/'.$coordinates, ['overview' => 'full', 'geometries' => 'geojson', 'steps' => 'false']);
                $route = $response->successful() ? $response->json('routes.0') : null;
                if (! is_array($route) || ! isset($route['distance'], $route['duration'])) {
                    $this->remoteUnavailable = true;

                    return null;
                }

                return [
                    'distanceKm' => round(((float) $route['distance']) / 1000, 2),
                    'durationMinutes' => max(1, (int) ceil(((float) $route['duration']) / 60)),
                    'geometry' => collect(data_get($route, 'geometry.coordinates', []))->map(fn ($point) => [(float) $point[1], (float) $point[0]])->all(),
                ];
            } catch (\Throwable) {
                $this->remoteUnavailable = true;

                return null;
            }
        });
    }

    private function circuity(float $distanceKm): float
    {
        return $distanceKm < 2 ? 1.28 : ($distanceKm < 8 ? 1.22 : 1.16);
    }
}
