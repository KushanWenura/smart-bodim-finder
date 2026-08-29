<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DecisionSupportController extends Controller
{
    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listingIds' => 'required|array|between:2,4', 'listingIds.*' => 'integer|distinct|exists:listings,id',
            'destinationId' => 'nullable|integer|exists:institutions,id', 'maxMonthlyTotalLkr' => 'nullable|integer|min:5000|max:3000000',
        ]);
        $destination = isset($data['destinationId']) ? Institution::find($data['destinationId']) : null;
        $listings = Listing::whereIn('id', $data['listingIds'])->where('status', 'published')
            ->with(['owner.ownerProfile', 'facilities', 'rentalSettings', 'nearbyPlaces'])->get();
        abort_unless($listings->count() === count($data['listingIds']), 422, 'Only published listings can be compared.');

        $rows = $listings->map(function (Listing $listing) use ($destination, $data) {
            $settings = $listing->rentalSettings;
            $costs = [
                'rent' => (int) $listing->monthly_price_lkr,
                'utilities' => (int) ($settings?->utilities_estimate_lkr ?? 3500),
                'meals' => (int) ($settings?->meals_estimate_lkr ?? 12000),
                'transport' => (int) ($settings?->transport_estimate_lkr ?? 5000),
            ];
            $total = array_sum($costs);
            $distance = $destination ? $this->distance((float) $listing->latitude, (float) $listing->longitude, (float) $destination->latitude, (float) $destination->longitude) : null;
            $nearbyTypes = $listing->nearbyPlaces->pluck('type')->unique();
            $verified = ($listing->owner->ownerProfile?->verification_status ?? null) === 'verified';
            $budgetFit = isset($data['maxMonthlyTotalLkr']) ? max(0, min(1, 1 - max(0, $total - $data['maxMonthlyTotalLkr']) / max(1, $data['maxMonthlyTotalLkr']))) : 0.7;
            $distanceFit = $distance === null ? 0.6 : max(0, min(1, 1 - ($distance / 25)));
            $essentialsFit = min(1, $nearbyTypes->intersect(['bus_station', 'train_station', 'supermarket', 'hospital', 'food'])->count() / 5);
            $score = (int) round($budgetFit * 30 + $distanceFit * 25 + min(1, (float) $listing->average_rating / 5) * 20 + $essentialsFit * 15 + ($verified ? 10 : 0));
            $reasons = collect([
                $verified ? 'Human-verified property owner' : null,
                $distance !== null ? round($distance, 1).' km from your selected destination' : null,
                $nearbyTypes->contains('bus_station') ? 'Bus access is recorded nearby' : null,
                $nearbyTypes->contains('supermarket') ? 'Grocery access is recorded nearby' : null,
                $total <= ($data['maxMonthlyTotalLkr'] ?? PHP_INT_MAX) ? 'Estimated total fits your monthly limit' : 'Estimated total exceeds your monthly limit',
            ])->filter()->values();

            return [
                'listingId' => $listing->id, 'title' => $listing->title, 'score' => $score,
                'monthlyCost' => $costs + ['total' => $total], 'distanceKm' => $distance === null ? null : round($distance, 2),
                'rating' => (float) $listing->average_rating, 'ownerVerified' => $verified,
                'facilities' => $listing->facilities->pluck('name')->values(), 'reasons' => $reasons,
                'disclaimer' => 'Utilities, meals and transport are owner estimates or disclosed platform defaults. Verify actual costs before reserving.',
            ];
        })->sortByDesc('score')->values()->map(fn ($row, $index) => $row + ['rank' => $index + 1]);

        $winner = $rows->first();

        return response()->json([
            'data' => $rows,
            'recommendation' => $winner ? $winner['title'].' ranks first because it has the strongest combined fit across estimated total cost, destination distance, resident rating, verified ownership and nearby essentials.' : null,
            'method' => 'Transparent weighted decision support. It does not override must-have filters or guarantee actual costs.',
        ]);
    }

    private function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
