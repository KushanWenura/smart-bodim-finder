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
            'organizationName' => $place->organization_name,
            'branchName' => $place->branch_name,
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
        $resolution = $proximity->resolution($data['destination']);
        if ($resolution['status'] === 'ambiguous') {
            return response()->json([
                'message' => 'This institution has several branches. Select the branch you mean.',
                'code' => 'ambiguous_destination',
                'organization' => $resolution['organization'],
                'suggestions' => $resolution['suggestions']->map(fn ($place) => ['id' => $place->id, 'name' => $place->name, 'branchName' => $place->branch_name])->values(),
            ], 422);
        }
        $destination = $resolution['destination'];
        if (! $destination) {
            return response()->json(['message' => 'Destination not found in the supported campus and workplace directory.', 'suggestions' => $proximity->destinations()->pluck('name')->take(8)], 422);
        }

        $query = Listing::publiclyVisible()->with(['owner.ownerProfile', 'facilities', 'images', 'nearbyPlaces']);
        $query->when($data['maxPrice'] ?? null, fn ($builder, $price) => $builder->where('monthly_price_lkr', '<=', $price));
        $query->when($data['facility'] ?? null, fn ($builder, $facility) => $builder->whereHas('facilities', fn ($facilities) => $facilities->where('name', $facility)));
        $radius = (float) ($data['radiusKm'] ?? 15);
        $ranked = $proximity->annotate($query->limit(250)->get(), $destination, $radius, 24);

        return response()->json([
            'destination' => ['id' => $destination->id, 'name' => $destination->name, 'type' => $destination->type, 'organizationName' => $destination->organization_name, 'branchName' => $destination->branch_name, 'latitude' => $destination->latitude, 'longitude' => $destination->longitude],
            'data' => ListingResource::collection($ranked),
            'meta' => ['total' => $ranked->count(), 'radiusKm' => $radius, 'distanceMethod' => 'Haversine eligibility radius', 'commuteEstimate' => $ranked->first()?->getAttribute('route_method') ?? 'Offline distance-derived estimate (not live traffic)', 'routeProvider' => str_contains((string) $ranked->first()?->getAttribute('route_method'), 'OSRM') ? 'osrm' : 'offline', 'commuteModes' => ['walking', 'driving', 'publicTransport']],
        ]);
    }
}
