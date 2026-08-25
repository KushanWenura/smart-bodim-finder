<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiFeedback;
use App\Models\Listing;
use App\Models\SearchLog;
use App\Services\PersonalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiFeedbackController extends Controller
{
    public function store(Request $request, PersonalizationService $personalization): JsonResponse
    {
        $data = $request->validate([
            'event' => 'required|in:result_click,favorite,enquiry,hide,moved_in,helpful,not_helpful',
            'listingId' => 'nullable|integer|exists:listings,id',
            'searchLogId' => 'nullable|integer|exists:search_logs,id',
            'position' => 'nullable|integer|min:1|max:100',
            'matchScore' => 'nullable|integer|min:1|max:99',
            'reason' => 'nullable|string|max:160',
            'breakdown' => 'nullable|array|max:10',
            'breakdown.*' => 'numeric|min:0|max:100',
        ]);
        if (isset($data['searchLogId'])) {
            SearchLog::query()->whereKey($data['searchLogId'])->where('user_id', $request->user()->id)->firstOrFail();
        }
        $listing = isset($data['listingId']) ? Listing::publiclyVisible()->with('facilities')->findOrFail($data['listingId']) : null;
        $feedback = AiFeedback::create([
            'user_id' => $request->user()->id,
            'search_log_id' => $data['searchLogId'] ?? null,
            'listing_id' => $listing?->id,
            'event_type' => $data['event'],
            'position' => $data['position'] ?? null,
            'match_score' => $data['matchScore'] ?? null,
            'metadata' => array_filter(['reason' => $data['reason'] ?? null, 'breakdown' => $data['breakdown'] ?? null]),
            'occurred_at' => now(),
        ]);
        $learned = $personalization->learn($request->user(), $data['event'], $listing);

        return response()->json([
            'recorded' => true,
            'id' => $feedback->id,
            'learningEnabled' => (bool) $request->user()->tenantProfile?->ai_learning_enabled,
            'signalCount' => (int) ($learned['signals'] ?? 0),
        ], 201);
    }
}
