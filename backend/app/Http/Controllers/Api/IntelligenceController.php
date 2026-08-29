<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Services\PriceIntelligenceService;
use App\Services\SriLankanAddressNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntelligenceController extends Controller
{
    public function address(Request $request, SriLankanAddressNormalizer $normalizer): JsonResponse
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:180']);

        return response()->json(['data' => $normalizer->resolve($data['q']), 'suggestions' => $normalizer->suggestions($data['q']), 'disclaimer' => 'Verify the map marker before publishing; this catalog is not a postal authority.']);
    }

    public function price(Listing $listing, PriceIntelligenceService $prices): JsonResponse
    {
        abort_unless($listing->status === 'published' || request()->user()?->id === $listing->owner_id || request()->user()?->role === 'admin', 404);

        return response()->json(['data' => $prices->assess($listing)]);
    }
}
