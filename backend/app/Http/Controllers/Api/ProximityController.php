<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\ProximityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProximityController extends Controller
{
    public function destinations(ProximityService $proximity): JsonResponse
    {
        return response()->json(['data' => $proximity->destinations()->map(fn ($place) => [
            'id' => $place->id,
            'name' => $place->name,
            'type' => $place->type,
            'latitude' => $place->latitude,
            'longitude' => $place->longitude,
        ])->values()]);
    }

    public function search(Request $request, ProximityService $proximity): JsonResponse
    {
        $data = $request->validate([
            'destination' => 'required|string|min:2|max:180',
            'radiusKm' => 'nullable|numeric|min:1|max:50',
            'maxPrice' => 'nullable|integer|min:5000|max:1000000',
            'facility' => 'nullable|string|max:100',
        ]);
        $destination = $proximity->resolve($data['destination']);
        if (! $destination) {
            return response()->json(['message' => 'Destination not found in the supported campus and workplace directory.', 'suggestions' => $proximity->destinations()->pluck('name')->take(8)], 422);
        }

        $query = Listing::publiclyVisible()->with(['owner.ownerProfile', 'facilities', 'images', 'nearbyPlaces']);
        $query->when($data['maxPrice'] ?? null, fn ($builder, $price) => $builder->where('monthly_price_lkr', '<=', $price));
        $query->when($data['facility'] ?? null, fn ($builder, $facility) => $builder->whereHas('facilities', fn ($facilities) => $facilities->where('name', $facility)));
        $radius = (float) ($data['radiusKm'] ?? 15);
        $ranked = $proximity->annotate($query->limit(250)->get(), $destination)->filter(fn ($listing) => (float) $listing->distance_km <= $radius)->take(24)->values();

        return response()->json([
            'destination' => ['id' => $destination->id, 'name' => $destination->name, 'type' => $destination->type, 'latitude' => $destination->latitude, 'longitude' => $destination->longitude],
            'data' => ListingResource::collection($ranked),
            'meta' => ['total' => $ranked->count(), 'radiusKm' => $radius, 'distanceMethod' => 'Haversine straight-line distance', 'commuteEstimate' => 'Distance-based estimate at 22 km/h; not live route time'],
        ]);
    }
}
