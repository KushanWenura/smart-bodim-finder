<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Services\Analytics;
use App\Services\AreaSafetyInsightService;
use Illuminate\Http\JsonResponse;

class AreaSafetyController extends Controller
{
    public function __invoke(Listing $listing, AreaSafetyInsightService $service): JsonResponse
    {
        abort_unless($listing->status === 'published' && $listing->available, 404);
        Analytics::record('area_safety_insight_viewed', $listing->id);

        return response()->json(['data' => $service->assess($listing)]);
    }
}
