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
            'issueCategory' => 'nullable|in:wrong_destination,budget_ignored,missing_facility,distance_incorrect,irrelevant_results,unclear_explanation,other',
            'breakdown' => 'nullable|array|max:10',
            'breakdown.*' => 'numeric|min:0|max:100',
        ]);
        $answerEvents = ['helpful', 'not_helpful'];
        abort_if(in_array($data['event'], $answerEvents, true) && empty($data['searchLogId']), 422, 'Answer feedback must reference your search.');
        if (isset($data['searchLogId'])) {
            SearchLog::query()->whereKey($data['searchLogId'])->where('user_id', $request->user()->id)->firstOrFail();
        }
        $listing = isset($data['listingId']) ? Listing::publiclyVisible()->with('facilities')->findOrFail($data['listingId']) : null;
        $attributes = [
            'user_id' => $request->user()->id,
            'search_log_id' => $data['searchLogId'] ?? null,
            'listing_id' => $listing?->id,
            'event_type' => $data['event'],
        ];
        $values = [
            'position' => $data['position'] ?? null,
            'match_score' => $data['matchScore'] ?? null,
            'metadata' => array_filter(['reason' => $data['reason'] ?? null, 'issueCategory' => $data['issueCategory'] ?? null, 'breakdown' => $data['breakdown'] ?? null]),
            'occurred_at' => now(),
        ];
        if (in_array($data['event'], $answerEvents, true)) {
            AiFeedback::query()->where('user_id', $request->user()->id)
                ->where('search_log_id', $data['searchLogId'])->whereNull('listing_id')
                ->whereIn('event_type', $answerEvents)->where('event_type', '!=', $data['event'])->delete();
            $feedback = AiFeedback::updateOrCreate($attributes, $values);
        } else {
            $feedback = AiFeedback::create(array_merge($attributes, $values));
        }
        $learned = $personalization->learn($request->user(), $data['event'], $listing);

        return response()->json([
            'recorded' => true,
            'id' => $feedback->id,
            'learningEnabled' => (bool) $request->user()->tenantProfile?->ai_learning_enabled,
            'signalCount' => (int) ($learned['signals'] ?? 0),
        ], $feedback->wasRecentlyCreated ? 201 : 200);
    }
}
