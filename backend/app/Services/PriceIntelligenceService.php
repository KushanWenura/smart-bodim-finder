<?php

namespace App\Services;

use App\Models\Listing;

class PriceIntelligenceService
{
    public const VERSION = 'transparent-peer-price-v1.0.0';

    public function assess(Listing $listing): array
    {
        $listing->loadMissing('facilities');
        $peers = Listing::query()->whereKeyNot($listing->id)->where('status', 'published')
            ->where('district', $listing->district)->where('property_type', $listing->property_type)
            ->with('facilities')->get();
        if ($peers->count() < 3) {
            $peers = Listing::query()->whereKeyNot($listing->id)->where('status', 'published')
                ->where('district', $listing->district)->with('facilities')->get();
        }
        if ($peers->isEmpty()) {
            return ['available' => false, 'label' => 'Not enough comparable listings', 'confidence' => 'insufficient', 'peerCount' => 0, 'method' => self::VERSION];
        }

        $prices = $peers->pluck('monthly_price_lkr')->map(fn ($value) => (int) $value)->sort()->values();
        $median = $this->percentile($prices->all(), .5);
        $price = (int) $listing->monthly_price_lkr;
        $ratio = $median > 0 ? $price / $median : 1;
        $label = $ratio < .9 ? 'Below local peer median' : ($ratio <= 1.1 ? 'Near local peer median' : 'Above local peer median');
        $percentile = (int) round(($prices->filter(fn ($value) => $value <= $price)->count() / max(1, $prices->count())) * 100);
        $facilitySignals = $listing->facilities->map(function ($facility) use ($peers): ?array {
            $with = $peers->filter(fn ($peer) => $peer->facilities->contains('id', $facility->id))->pluck('monthly_price_lkr')->map(fn ($v) => (int) $v)->sort()->values()->all();
            $without = $peers->reject(fn ($peer) => $peer->facilities->contains('id', $facility->id))->pluck('monthly_price_lkr')->map(fn ($v) => (int) $v)->sort()->values()->all();
            if (count($with) < 2 || count($without) < 2) {
                return null;
            }
            $difference = max(-5000, min(5000, $this->percentile($with, .5) - $this->percentile($without, .5)));

            return ['facility' => $facility->name, 'observedDifferenceLkr' => $difference];
        })->filter()->sortByDesc(fn ($row) => abs($row['observedDifferenceLkr']))->take(4)->values()->all();

        return [
            'available' => true, 'label' => $label, 'confidence' => $peers->count() >= 8 ? 'high' : ($peers->count() >= 4 ? 'medium' : 'low'),
            'peerCount' => $peers->count(), 'listingPriceLkr' => $price, 'peerMedianLkr' => $median,
            'peerRangeLkr' => ['low' => $this->percentile($prices->all(), .25), 'high' => $this->percentile($prices->all(), .75)],
            'priceVsMedianPercent' => (int) round(($ratio - 1) * 100), 'marketPercentile' => $percentile,
            'facilitySignals' => $facilitySignals, 'method' => self::VERSION,
            'disclaimer' => 'Peer statistics use published BodimBuddy listings, not an official valuation. Condition, exact location and included bills can change fair rent.',
        ];
    }

    private function percentile(array $values, float $percentile): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $index = ($percentile * (count($values) - 1));
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return (int) round($values[$lower] + (($values[$upper] - $values[$lower]) * ($index - $lower)));
    }
}
