<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeReview;
use App\Models\Listing;
use App\Models\Review;
use App\Notifications\PlatformNotification;
use App\Services\Analytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    public function mine(Request $r): JsonResponse
    {
        return response()->json(['data' => Review::with('listing:id,title,public_slug')->where('tenant_id', $r->user()->id)->latest()->get()]);
    }

    public function ownerReviews(Request $r): JsonResponse
    {
        return response()->json(['data' => Review::with(['tenant:id,name', 'listing:id,title,owner_id'])->whereHas('listing', fn ($query) => $query->where('owner_id', $r->user()->id))->latest()->paginate(30)]);
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate(['listingId' => 'required|exists:listings,id', 'rating' => 'required|integer|between:1,5', 'text' => 'required|string|min:15|max:2000']);
        $listing = Listing::findOrFail($data['listingId']);
        abort_unless($listing->status === 'published', 404);
        abort_if($listing->owner_id === $r->user()->id, 403, 'Owners cannot review their own property.');
        $review = DB::transaction(function () use ($r, $data, $listing) {
            $review = Review::updateOrCreate(['tenant_id' => $r->user()->id, 'listing_id' => $listing->id], ['rating' => $data['rating'], 'body' => $data['text'], 'moderation_status' => 'visible']);
            $visible = Review::where('listing_id', $listing->id)->where('moderation_status', 'visible');
            $listing->update(['average_rating' => round((float) $visible->avg('rating'), 2), 'review_count' => $visible->count()]);

            return $review;
        });
        $listing->owner->notify(new PlatformNotification('review', 'New review', "{$r->user()->name} reviewed {$listing->title}.", '/owner/reviews'));
        AnalyzeReview::dispatch($review->id)->afterCommit();
        Analytics::record('review_submitted', $listing->id, ['rating' => $review->rating]);

        return response()->json(['data' => $review], 201);
    }

    public function destroy(Request $r, Review $review): JsonResponse
    {
        abort_unless($review->tenant_id === $r->user()->id, 403);
        $listing = $review->listing;
        $review->delete();
        $this->recalculate($listing);

        return response()->json(status: 204);
    }

    private function recalculate(Listing $listing): void
    {
        $visible = Review::where('listing_id', $listing->id)->where('moderation_status', 'visible');
        $listing->update(['average_rating' => round((float) ($visible->avg('rating') ?? 0), 2), 'review_count' => $visible->count()]);
    }

    public function report(Request $r, Review $review): JsonResponse
    {
        $data = $r->validate(['reason' => 'required|string|min:10|max:500']);
        DB::table('review_reports')->updateOrInsert(['review_id' => $review->id, 'reporter_id' => $r->user()->id], ['reason' => $data['reason'], 'status' => 'open', 'updated_at' => now(), 'created_at' => now()]);
        $review->update(['moderation_status' => 'flagged']);

        return response()->json(['message' => 'Review reported.'], 201);
    }
}
