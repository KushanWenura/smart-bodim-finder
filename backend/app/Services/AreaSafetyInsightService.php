<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingNearbyPlace;
use Illuminate\Support\Collection;

class AreaSafetyInsightService
{
    private const VERSION = 'area-safety-evidence-v1.1.0';

    public function __construct(private readonly AiServiceClient $ai) {}

    private const PLACE_LABELS = [
        'police' => 'Police station',
        'hospital' => 'Hospital',
        'pharmacy' => 'Pharmacy',
        'bus_station' => 'Bus stop',
        'train_station' => 'Train station',
        'supermarket' => 'Active grocery or supermarket',
        'food' => 'Active food place',
    ];

    public function assess(Listing $listing): array
    {
        $listing->loadMissing(['owner.ownerProfile', 'facilities', 'nearbyPlaces', 'areaSafetyReports']);
        $places = $listing->nearbyPlaces->sortBy('distance_m')->values();
        $facilityNames = $listing->facilities->pluck('name')->map(fn (string $name) => mb_strtolower($name));

        $emergency = $this->emergencyAccess($places);
        $mobility = $this->nightMobilityProxy($places);
        $property = $this->propertyProtection($listing, $facilityNames);
        $community = $this->communityEvidence($listing);
        $environment = $this->environmentEvidence();
        $dimensions = collect([$emergency, $mobility, $property, $community['dimension'], $environment]);

        $available = $dimensions->filter(fn (array $dimension) => is_int($dimension['score']));
        $weightTotal = max(1, $available->sum('weight'));
        $score = (int) round($available->sum(fn (array $dimension) => $dimension['score'] * $dimension['weight']) / $weightTotal);
        $sourceQuality = $this->sourceQuality($places);
        $coverage = $dimensions->filter(fn (array $dimension) => $dimension['status'] === 'available')->count() / $dimensions->count();
        $communityQuality = $community['quality'];
        $confidenceScore = (int) round(($sourceQuality * 50) + ($coverage * 30) + ($communityQuality * 20));
        $confidence = $confidenceScore >= 75 ? 'High' : ($confidenceScore >= 50 ? 'Medium' : 'Low');

        $signals = $places
            ->filter(fn (ListingNearbyPlace $place) => array_key_exists($place->type, self::PLACE_LABELS))
            ->groupBy('type')
            ->map(fn (Collection $group) => $this->signal($group->first()))
            ->values()
            ->sortBy('distanceM')
            ->values();

        $gaps = $dimensions
            ->filter(fn (array $dimension) => $dimension['status'] !== 'available')
            ->map(fn (array $dimension) => $dimension['gap'])
            ->values()
            ->all();

        if ($sourceQuality < 0.65) {
            array_unshift($gaps, 'Several nearby-place records are demonstration or lower-confidence records and must be confirmed before a viewing.');
        }

        $closestPolice = $places->firstWhere('type', 'police');
        $closestHospital = $places->firstWhere('type', 'hospital');

        return [
            'listingId' => $listing->id,
            'generatedAt' => now()->toIso8601String(),
            'score' => $score,
            'label' => $this->scoreLabel($score),
            'confidence' => [
                'level' => $confidence,
                'score' => $confidenceScore,
                'reason' => $this->confidenceReason($confidence, $sourceQuality, $coverage, $communityQuality),
            ],
            'summary' => $this->summary($listing, $score, $confidence, $closestPolice, $closestHospital),
            'dimensions' => $dimensions->values()->all(),
            'signals' => $signals->all(),
            'communityInsights' => $community['insights'],
            'dataGaps' => array_values(array_unique($gaps)),
            'guidance' => [
                'Visit the area during both daylight and evening hours with someone you trust.',
                'Confirm lighting, transport frequency and the displayed nearby services in person.',
                'For immediate danger or an incident, contact the Sri Lanka Police or the relevant emergency service.',
            ],
            'map' => [
                'latitude' => round((float) $listing->latitude, 3),
                'longitude' => round((float) $listing->longitude, 3),
                'privacy' => 'Approximate public-area marker; the private street address is never returned.',
                'highlightTypes' => ['police', 'hospital', 'pharmacy', 'bus_station', 'train_station'],
            ],
            'method' => [
                'name' => 'Transparent hybrid safety evidence baseline',
                'version' => self::VERSION,
                'scoreEngine' => 'Deterministic weighted evidence model',
                'explanationEngine' => 'Buddy evidence summary',
                'trainingReadiness' => 'The checked-in CC0 synthetic corpus trains/tests language understanding only. Live scores use moderated community reports and never import synthetic rows as area evidence.',
            ],
            'disclaimer' => 'This is decision support, not a guarantee that an area is safe. It does not predict crime and must not replace an in-person visit, local advice or official emergency information.',
        ];
    }

    private function emergencyAccess(Collection $places): array
    {
        $weights = ['police' => 0.45, 'hospital' => 0.35, 'pharmacy' => 0.20];
        $scores = collect($weights)->map(function (float $weight, string $type) use ($places) {
            $place = $places->firstWhere('type', $type);

            return $place ? ['score' => $this->distanceScore((int) $place->distance_m, $type), 'weight' => $weight] : null;
        })->filter();

        if ($scores->isEmpty()) {
            return $this->unavailableDimension('emergency_access', 'Emergency access', 'bi-hospital', 40, 'Police, hospital and pharmacy coordinates are not available.');
        }

        $score = (int) round($scores->sum(fn (array $item) => $item['score'] * $item['weight']) / $scores->sum('weight'));

        return $this->dimension('emergency_access', 'Emergency access', 'bi-hospital', $score, 40, 'Proximity to police, hospital and pharmacy support. Distance does not measure response time.');
    }

    private function nightMobilityProxy(Collection $places): array
    {
        $weights = ['bus_station' => 0.35, 'train_station' => 0.20, 'supermarket' => 0.25, 'food' => 0.20];
        $scores = collect($weights)->map(function (float $weight, string $type) use ($places) {
            $place = $places->firstWhere('type', $type);

            return $place ? ['score' => $this->distanceScore((int) $place->distance_m, $type), 'weight' => $weight] : null;
        })->filter();

        if ($scores->isEmpty()) {
            return $this->unavailableDimension('mobility_activity', 'Travel and active-place access', 'bi-moon-stars', 35, 'Transport and active-place coordinates are not available.');
        }

        $score = (int) round($scores->sum(fn (array $item) => $item['score'] * $item['weight']) / $scores->sum('weight'));

        return $this->dimension('mobility_activity', 'Travel and active-place access', 'bi-moon-stars', $score, 35, 'Uses nearby transport and active public places as an accessibility proxy. Street-lighting and service-hour data are not yet available.');
    }

    private function propertyProtection(Listing $listing, Collection $facilities): array
    {
        $score = 45;
        $evidence = [];
        if ($facilities->contains(fn (string $name) => str_contains($name, 'security') || str_contains($name, 'cctv'))) {
            $score += 35;
            $evidence[] = 'Security/CCTV is declared for this listing';
        }
        if (($listing->owner?->ownerProfile?->verification_status ?? null) === 'verified') {
            $score += 15;
            $evidence[] = 'The platform has verified the owner profile';
        }

        return $this->dimension(
            'property_protection',
            'Property protection',
            'bi-house-lock',
            min(95, $score),
            25,
            $evidence ? implode('. ', $evidence).'. Confirm every feature during the viewing.' : 'No verified property-protection feature is currently recorded; confirm locks, access and lighting during the viewing.'
        );
    }

    private function communityEvidence(Listing $listing): array
    {
        $reports = $listing->areaSafetyReports
            ->where('moderation_status', 'visible')
            ->values();
        $reportCount = $reports->count();
        $eveningCount = $reports->whereIn('visit_period', ['evening', 'both'])->count();
        $researchConsentCount = $reports->where('consent_for_research', true)->count();
        $themeAnalysis = $this->ai->analyzeSafetyReports($reports->map(fn ($report) => [
            'text' => $report->comment,
            // Moderation checks content quality; it is not independent proof of an event.
            'verified' => false,
        ])->all());

        $insights = [
            'moderatedReportCount' => $reportCount,
            'eveningReportCount' => $eveningCount,
            'researchConsentCount' => $researchConsentCount,
            'minimumForScore' => 3,
            'themes' => $themeAnalysis['themes'] ?? [],
            'modelVersion' => $themeAnalysis['modelVersion'] ?? 'unavailable',
            'modelOnline' => (bool) ($themeAnalysis['online'] ?? false),
            'evidencePolicy' => $themeAnalysis['evidencePolicy'] ?? 'Community observations are context, not verified crime statistics.',
            'source' => 'BodimBuddy moderated, consented community observations',
        ];

        if ($reportCount < 3) {
            return [
                'dimension' => $this->unavailableDimension(
                    'community_observations',
                    'Community observations',
                    'bi-people',
                    15,
                    $reportCount
                        ? "Only {$reportCount} moderated observation(s) are available; at least 3 are required before community ratings affect the score."
                        : 'No moderated day/evening community observations have been collected for this area.'
                ),
                'quality' => min(0.30, $reportCount * 0.10),
                'insights' => $insights,
            ];
        }

        $ratingFields = ['lighting_rating', 'transport_rating', 'public_activity_rating', 'road_safety_rating', 'emergency_access_rating'];
        $meanRating = collect($ratingFields)->average(fn (string $field) => (float) $reports->avg($field));
        $score = (int) round(max(0, min(100, (($meanRating - 1) / 4) * 100)));
        $quality = min(1.0, ($reportCount / 8) * 0.65 + ($eveningCount / 3) * 0.35);

        return [
            'dimension' => $this->dimension(
                'community_observations',
                'Community observations',
                'bi-people',
                $score,
                15,
                "Based on {$reportCount} moderated structured observation(s), including {$eveningCount} covering evening conditions. These are opinions, not independently verified incident records."
            ),
            'quality' => $quality,
            'insights' => $insights,
        ];
    }

    private function environmentEvidence(): array
    {
        return $this->unavailableDimension('environmental_hazards', 'Environmental hazards', 'bi-cloud-rain-heavy', 0, 'An official DMC flood or landslide layer has not yet been connected for this coordinate.');
    }

    private function dimension(string $key, string $label, string $icon, int $score, int $weight, string $explanation): array
    {
        return compact('key', 'label', 'icon', 'score', 'weight', 'explanation') + ['status' => 'available', 'gap' => null];
    }

    private function unavailableDimension(string $key, string $label, string $icon, int $weight, string $gap): array
    {
        return compact('key', 'label', 'icon', 'weight', 'gap') + ['score' => null, 'status' => 'limited', 'explanation' => $gap];
    }

    private function signal(ListingNearbyPlace $place): array
    {
        $quality = $this->placeConfidence($place);

        return [
            'type' => $place->type,
            'label' => self::PLACE_LABELS[$place->type],
            'name' => $place->name,
            'distanceM' => (int) $place->distance_m,
            'latitude' => $place->latitude ? (float) $place->latitude : null,
            'longitude' => $place->longitude ? (float) $place->longitude : null,
            'sourceProvider' => $place->source_provider ?: 'unrecorded',
            'sourceConfidence' => (int) round($quality * 100),
            'needsConfirmation' => $quality < 0.70,
        ];
    }

    private function distanceScore(int $distance, string $type): int
    {
        $thresholds = in_array($type, ['police', 'hospital', 'train_station'], true)
            ? [[1500, 95], [3000, 85], [5000, 72], [10000, 55]]
            : [[800, 95], [1500, 86], [3000, 72], [5000, 58]];

        foreach ($thresholds as [$limit, $score]) {
            if ($distance <= $limit) {
                return $score;
            }
        }

        return 38;
    }

    private function sourceQuality(Collection $places): float
    {
        $relevant = $places->filter(fn (ListingNearbyPlace $place) => array_key_exists($place->type, self::PLACE_LABELS));

        return $relevant->isEmpty() ? 0.15 : (float) $relevant->average(fn (ListingNearbyPlace $place) => $this->placeConfidence($place));
    }

    private function placeConfidence(ListingNearbyPlace $place): float
    {
        $confidence = $place->coordinate_confidence !== null ? (float) $place->coordinate_confidence : 0.35;
        if ($place->source_provider === 'project-fixture') {
            $confidence = min($confidence, 0.40);
        }
        if (! $place->verified_at) {
            $confidence = min($confidence, 0.45);
        }

        return max(0.05, min(1.0, $confidence));
    }

    private function scoreLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Strong supporting evidence',
            $score >= 65 => 'Good supporting evidence',
            $score >= 50 => 'Mixed supporting evidence',
            default => 'Limited supporting evidence',
        };
    }

    private function confidenceReason(string $level, float $sourceQuality, float $coverage, float $communityQuality): string
    {
        return "{$level} confidence based on ".round($coverage * 100).'% category coverage, '.round($sourceQuality * 100).'% nearby-source confidence and '.round($communityQuality * 100).'% community evidence quality. Missing evidence never counts as safe.';
    }

    private function summary(Listing $listing, int $score, string $confidence, ?ListingNearbyPlace $police, ?ListingNearbyPlace $hospital): string
    {
        $parts = ["Buddy found a {$score}/100 evidence score around {$listing->public_area}, with {$confidence} data confidence."];
        if ($police) {
            $parts[] = 'The nearest recorded police station is '.$this->formatDistance((int) $police->distance_m).' away.';
        }
        if ($hospital) {
            $parts[] = 'The nearest recorded hospital is '.$this->formatDistance((int) $hospital->distance_m).' away.';
        }
        $parts[] = 'This describes available support signals, not the likelihood of crime or a guarantee of personal safety.';

        return implode(' ', $parts);
    }

    private function formatDistance(int $metres): string
    {
        return $metres < 1000 ? "{$metres} m" : number_format($metres / 1000, 1).' km';
    }
}
