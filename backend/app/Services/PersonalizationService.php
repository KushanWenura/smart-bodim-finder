<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;

class PersonalizationService
{
    private const EVENT_WEIGHT = [
        'result_click' => 1,
        'favorite' => 3,
        'enquiry' => 5,
        'hide' => -4,
        'moved_in' => 8,
        'helpful' => 1,
        'not_helpful' => -1,
    ];

    public function preferences(?User $user): array
    {
        if (! $user || $user->role !== 'tenant') {
            return [];
        }
        $profile = $user->tenantProfile;
        if (! $profile || ! $profile->ai_learning_enabled) {
            return [];
        }

        return $profile->learned_preferences ?? [];
    }

    public function learn(User $user, string $event, ?Listing $listing): array
    {
        $profile = $user->tenantProfile;
        if (! $profile || ! $profile->ai_learning_enabled || ! $listing) {
            return $profile?->learned_preferences ?? [];
        }
        $weight = self::EVENT_WEIGHT[$event] ?? 0;
        if ($weight === 0) {
            return $profile->learned_preferences ?? [];
        }
        $listing->loadMissing('facilities');
        $learned = $profile->learned_preferences ?? ['signals' => 0, 'facilities' => [], 'areas' => [], 'priceTotal' => 0, 'priceWeight' => 0];
        $learned['signals'] = max(0, (int) ($learned['signals'] ?? 0) + 1);
        foreach ($listing->facilities->pluck('name') as $facility) {
            $learned['facilities'][$facility] = max(-20, min(50, (int) ($learned['facilities'][$facility] ?? 0) + $weight));
        }
        $area = $listing->public_area;
        $learned['areas'][$area] = max(-20, min(50, (int) ($learned['areas'][$area] ?? 0) + $weight));
        if ($weight > 0) {
            $learned['priceTotal'] = (int) ($learned['priceTotal'] ?? 0) + ((int) $listing->monthly_price_lkr * $weight);
            $learned['priceWeight'] = (int) ($learned['priceWeight'] ?? 0) + $weight;
            $learned['preferredPrice'] = (int) round($learned['priceTotal'] / max(1, $learned['priceWeight']));
        }
        arsort($learned['facilities']);
        arsort($learned['areas']);
        $learned['facilities'] = array_slice($learned['facilities'], 0, 12, true);
        $learned['areas'] = array_slice($learned['areas'], 0, 8, true);
        $profile->update(['learned_preferences' => $learned]);

        return $learned;
    }

    public function listingBoost(Listing $listing, array $preferences): array
    {
        if (($preferences['signals'] ?? 0) < 3) {
            return ['score' => 0.0, 'reasons' => []];
        }
        $facilityWeights = collect($preferences['facilities'] ?? []);
        $facilityScore = $listing->facilities->pluck('name')->sum(fn ($name) => max(0, (int) $facilityWeights->get($name, 0)));
        $areaScore = max(0, (int) data_get($preferences, 'areas.'.$listing->public_area, 0));
        $preferredPrice = (int) ($preferences['preferredPrice'] ?? 0);
        $priceFit = $preferredPrice > 0 ? max(0, 1 - abs(((int) $listing->monthly_price_lkr - $preferredPrice) / $preferredPrice)) : 0;
        $boost = min(6.0, ($facilityScore / 20) + ($areaScore / 12) + ($priceFit * 2));
        $reasons = [];
        if ($facilityScore > 4) {
            $reasons[] = 'Fits facilities you often shortlist';
        }
        if ($areaScore > 2) {
            $reasons[] = 'In an area you often explore';
        }

        return ['score' => $boost, 'reasons' => $reasons];
    }
}
