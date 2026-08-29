<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Facility;
use App\Models\Listing;
use App\Models\Location;
use App\Models\Review;
use App\Services\AiServiceClient;
use App\Services\Analytics;
use App\Services\PriceIntelligenceService;
use App\Services\ReservationAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListingController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['city' => 'nullable|string|max:80', 'type' => 'nullable|string|max:50', 'gender' => 'nullable|in:any,male_only,female_only', 'facility' => 'nullable|string|max:100', 'minPrice' => 'nullable|integer|min:0', 'maxPrice' => 'nullable|integer|min:0', 'minRating' => 'nullable|numeric|min:0|max:5', 'sort' => 'nullable|in:newest,price_asc,price_desc,rating', 'perPage' => 'nullable|integer|min:1|max:24']);
        $q = Listing::query()->publiclyVisible()->with(['owner.ownerProfile', 'facilities', 'images']);
        $q->when($data['city'] ?? null, fn ($x, $v) => $x->where(fn ($z) => $z->where('city', 'like', "%$v%")->orWhere('public_area', 'like', "%$v%")))
            ->when($data['type'] ?? null, fn ($x, $v) => $x->where('property_type', $v))->when($data['gender'] ?? null, fn ($x, $v) => $x->where('gender_rule', $v))
            ->when($data['minPrice'] ?? null, fn ($x, $v) => $x->where('monthly_price_lkr', '>=', $v))->when($data['maxPrice'] ?? null, fn ($x, $v) => $x->where('monthly_price_lkr', '<=', $v))
            ->when($data['minRating'] ?? null, fn ($x, $v) => $x->where('average_rating', '>=', $v))->when($data['facility'] ?? null, fn ($x, $v) => $x->whereHas('facilities', fn ($f) => $f->where('name', $v)));
        match ($data['sort'] ?? 'newest') {
            'price_asc' => $q->orderBy('monthly_price_lkr'),'price_desc' => $q->orderByDesc('monthly_price_lkr'),'rating' => $q->orderByDesc('average_rating'),default => $q->latest('published_at')
        };

        return ListingResource::collection($q->paginate($data['perPage'] ?? 12)->withQueryString());
    }

    public function show(Request $request, Listing $listing, AiServiceClient $ai, ReservationAvailabilityService $availability, PriceIntelligenceService $prices): JsonResponse
    {
        abort_unless($listing->status === 'published', 404);
        $snapshot = $availability->snapshot($listing);
        $listing->setAttribute('availability_status', $snapshot['status']);
        $listing->setAttribute('availability_label', $snapshot['label']);
        $listing->setAttribute('next_available_from', $snapshot['nextAvailableFrom'] ?? null);
        $listing->setAttribute('hold_expires_at', $snapshot['holdExpiresAt'] ?? null);
        $listing->increment('view_count');
        Analytics::record('listing_detail_viewed', $listing->id);
        $listing->load(['owner.ownerProfile', 'facilities', 'images', 'nearbyPlaces']);
        $reviews = Review::with('tenant:id,name')->where('listing_id', $listing->id)->where('moderation_status', 'visible')->latest()->get();

        $favorite = $request->user()?->role === 'tenant' && DB::table('favorites')->where(['user_id' => $request->user()->id, 'listing_id' => $listing->id])->exists();

        return response()->json(['data' => new ListingResource($listing), 'favorite' => $favorite, 'reviews' => $reviews, 'reviewSummary' => $ai->summarize($reviews->pluck('body')->all()), 'priceIntelligence' => $prices->assess($listing), 'related' => ListingResource::collection(Listing::publiclyVisible()->where('id', '!=', $listing->id)->where('city', $listing->city)->with(['owner', 'facilities', 'images'])->limit(3)->get())]);
    }

    public function featured(): JsonResponse
    {
        return response()->json(['data' => ListingResource::collection(Listing::publiclyVisible()->with(['owner', 'facilities', 'images'])->orderByDesc('favorite_count')->limit(6)->get())]);
    }

    public function meta(): JsonResponse
    {
        return response()->json(['cities' => Location::where('active', true)->distinct()->orderBy('city')->pluck('city'), 'facilities' => Facility::where('active', true)->orderBy('name')->get(), 'propertyTypes' => ['boarding_room', 'shared_room', 'private_room', 'annex', 'studio', 'small_house', 'hostel']]);
    }

    public function report(Request $request, Listing $listing): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:500']);
        DB::table('listing_reports')->updateOrInsert(['listing_id' => $listing->id, 'reporter_id' => $request->user()->id], ['reason' => $data['reason'], 'status' => 'open', 'updated_at' => now(), 'created_at' => now()]);
        Analytics::record('listing_reported', $listing->id);

        return response()->json(['message' => 'Listing report submitted for administrator review.'], 201);
    }
}
