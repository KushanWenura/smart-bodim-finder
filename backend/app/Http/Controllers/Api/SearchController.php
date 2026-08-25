<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\SearchLog;
use App\Services\AiServiceClient;
use App\Services\ProximityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function assistant(Request $request, AiServiceClient $ai, ProximityService $proximity): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|min:2|max:500']);
        $message = trim($data['message']);
        $normalized = mb_strtolower($message);
        $query = Listing::publiclyVisible()->with(['owner', 'facilities', 'images', 'nearbyPlaces']);
        $interpreted = [];
        $destinationResolution = $proximity->resolution($message);
        if ($destinationResolution['status'] === 'ambiguous') {
            $suggestions = $destinationResolution['suggestions']->map(fn ($place) => [
                'id' => $place->id,
                'name' => $place->name,
                'branchName' => $place->branch_name,
            ])->values();
            $organization = $destinationResolution['organization'] ?? 'That institution';
            $interpreted['destination'] = ['status' => 'ambiguous', 'organization' => $organization];
            SearchLog::create(['user_id' => $request->user()?->id, 'sanitized_query' => $message, 'filters' => $interpreted, 'result_count' => 0, 'mode' => 'assistant:branch-clarification', 'latency_ms' => 0]);

            return response()->json([
                'answer' => $organization.' has several branches in Sri Lanka. Which branch should I measure from?',
                'results' => [],
                'suggestions' => $suggestions,
                'interpreted' => $interpreted,
                'search' => ['mode' => 'branch-clarification', 'aiOnline' => true, 'warning' => null],
                'disclaimer' => 'Choose the correct branch so distance and nearby-place calculations use the right coordinates.',
            ]);
        }
        $destination = $destinationResolution['destination'];
        if ($destination) {
            $interpreted['destination'] = ['name' => $destination->name, 'type' => $destination->type, 'organizationName' => $destination->organization_name, 'branchName' => $destination->branch_name];
        }

        $nearbyAreas = ['maharagama' => ['Nugegoda', 'Homagama'], 'moratuwa' => ['Moratuwa', 'Dehiwala'], 'peradeniya' => ['Peradeniya', 'Kandy City'], 'malabe' => ['Malabe', 'Homagama']];
        foreach ($destination ? [] : $nearbyAreas as $needle => $areas) {
            if (str_contains($normalized, $needle)) {
                $query->whereIn('public_area', $areas);
                $interpreted['location'] = ucfirst($needle);
                $interpreted['nearbyAreas'] = $areas;
                break;
            }
        }
        if (! $destination && ! isset($interpreted['location'])) {
            $knownPlaces = Listing::publiclyVisible()->get(['city', 'public_area'])->flatMap(fn ($listing) => [$listing->city, $listing->public_area])->filter()->unique()->sortByDesc(fn ($place) => mb_strlen($place));
            foreach ($knownPlaces as $place) {
                if (str_contains($normalized, mb_strtolower($place))) {
                    $query->where(fn ($builder) => $builder->where('city', $place)->orWhere('public_area', $place));
                    $interpreted['location'] = $place;
                    break;
                }
            }
        }
        if (preg_match('/(?:under|below|maximum|max|budget(?:\s+of)?|less\s+than)\s*(?:rs\.?|lkr)?\s*([0-9][0-9,]*)/i', $message, $matches)) {
            $budget = (int) str_replace(',', '', $matches[1]);
            if ($budget >= 5000) {
                $query->where('monthly_price_lkr', '<=', $budget);
                $interpreted['maxPrice'] = $budget;
            }
        }
        $propertyAliases = ['annex' => 'annex', 'studio' => 'studio', 'hostel' => 'hostel', 'shared room' => 'shared_room', 'private room' => 'private_room', 'boarding room' => 'boarding_room', 'small house' => 'small_house'];
        foreach ($propertyAliases as $needle => $type) {
            if (str_contains($normalized, $needle)) {
                $query->where('property_type', $type);
                $interpreted['propertyType'] = $type;
                break;
            }
        }
        if (preg_match('/\b(female|women|girls)\b/i', $message)) {
            $query->where('gender_rule', 'female_only');
            $interpreted['gender'] = 'female_only';
        } elseif (preg_match('/\b(male|men|boys)\b/i', $message)) {
            $query->where('gender_rule', 'male_only');
            $interpreted['gender'] = 'male_only';
        }
        $facilityAliases = ['wifi' => 'WiFi', 'parking' => 'Parking', 'meals' => 'Meals', 'kitchen' => 'Kitchen access', 'attached bathroom' => 'Attached bathroom', 'hot water' => 'Hot water', 'air conditioning' => 'Air conditioning'];
        $facilities = collect($facilityAliases)->filter(fn ($name, $needle) => str_contains($normalized, $needle))->values();
        foreach ($facilities as $facility) {
            $query->whereHas('facilities', fn ($builder) => $builder->where('name', $facility));
        }
        if ($facilities->isNotEmpty()) {
            $interpreted['facilities'] = $facilities->all();
        }

        $eligible = $query->limit(200)->get();
        if ($destination) {
            $radiusKm = 15.0;
            if (preg_match('/(?:within|inside|less than|under)\s*([0-9]+(?:\.[0-9]+)?)\s*km/i', $message, $distanceMatch)) {
                $radiusKm = min(50, max(1, (float) $distanceMatch[1]));
            }
            $eligible = $proximity->annotate($eligible, $destination)->filter(fn ($listing) => (float) $listing->distance_km <= $radiusKm)->values();
            $interpreted['radiusKm'] = $radiusKm;
        }
        $ranked = $ai->search($message, $eligible->map(fn ($listing) => ['id' => $listing->id, 'title' => $listing->title, 'description' => $listing->description, 'area' => $listing->public_area, 'city' => $listing->city, 'facilities' => $listing->facilities->pluck('name')->all()])->all(), 12);
        $scores = collect($ranked['results'] ?? [])->pluck('score', 'id');
        $ordered = $eligible->filter(fn ($listing) => $scores->has($listing->id))->sortByDesc(fn ($listing) => $scores[$listing->id])->values();
        if ($ordered->isEmpty() && $eligible->isNotEmpty()) {
            $ordered = $eligible->sortByDesc(fn ($listing) => ($listing->average_rating * 10) + $listing->favorite_count)->values();
            $ranked['mode'] = 'structured-nearby-fallback';
        }
        if ($destination) {
            $ordered = $ordered->sortBy('distance_km')->values();
        }
        $results = $ordered->take(5);
        $locationText = $destination ? ' near '.$destination->name : (isset($interpreted['location']) ? ' around '.$interpreted['location'] : '');
        $answer = $results->isEmpty()
            ? "I couldn't find a published place matching every part of that request. Try a nearby area or a slightly wider budget."
            : 'I found '.$results->count().' verified '.str('place')->plural($results->count()).$locationText.'. '.($destination ? 'They are ordered by straight-line distance, with nearby transport, food, supermarket and hospital information.' : 'I ranked these by your wording, then kept hard filters such as budget and facilities.');
        SearchLog::create(['user_id' => $request->user()?->id, 'sanitized_query' => $message, 'filters' => $interpreted, 'result_count' => $results->count(), 'mode' => 'assistant:'.($ranked['mode'] ?? 'semantic'), 'latency_ms' => 0]);

        return response()->json(['answer' => $answer, 'results' => ListingResource::collection($results), 'interpreted' => $interpreted, 'search' => ['mode' => $ranked['mode'] ?? 'semantic', 'aiOnline' => $ranked['online'] ?? false, 'warning' => $ranked['warning'] ?? null], 'disclaimer' => 'Bodim AI recommends published listings only. Verify the property in person before paying; exact addresses remain private.']);
    }

    public function recommendations(Request $request, AiServiceClient $ai)
    {
        $profile = $request->user()->tenantProfile;
        $eligible = Listing::publiclyVisible()->with(['owner', 'facilities', 'images'])->limit(500)->get();
        $preference = trim(implode(' ', array_filter([$profile?->preference_text, $profile?->institution_or_workplace, $profile?->required_facilities ? implode(' ', $profile->required_facilities) : null])));
        if ($preference === '') {
            return response()->json(['data' => ListingResource::collection($eligible->sortByDesc('favorite_count')->take(12)->values()), 'recommendation' => ['mode' => 'cold-start-popular', 'aiOnline' => null]]);
        }
        $ranked = $ai->search($preference, $eligible->map(fn ($listing) => ['id' => $listing->id, 'title' => $listing->title, 'description' => $listing->description, 'area' => $listing->public_area, 'city' => $listing->city, 'facilities' => $listing->facilities->pluck('name')->all()])->all(), 12);
        $scores = collect($ranked['results'])->pluck('score', 'id');
        $ordered = $eligible->filter(fn ($listing) => $scores->has($listing->id))->sortByDesc(fn ($listing) => $scores[$listing->id])->values();

        return response()->json(['data' => ListingResource::collection($ordered), 'recommendation' => ['mode' => $ranked['mode'] ?? 'semantic', 'aiOnline' => $ranked['online'], 'warning' => $ranked['warning'] ?? null]]);
    }

    public function __invoke(Request $request, AiServiceClient $ai)
    {
        $data = $request->validate(['q' => 'nullable|string|max:500', 'city' => 'nullable|string|max:80', 'type' => 'nullable|string|max:50', 'gender' => 'nullable|in:any,male_only,female_only', 'facility' => 'nullable|string|max:100', 'minPrice' => 'nullable|integer|min:0', 'maxPrice' => 'nullable|integer|min:0', 'minRating' => 'nullable|numeric|between:0,5', 'occupancy' => 'nullable|integer|between:1,20', 'furnished' => 'nullable|boolean', 'sort' => 'nullable|in:relevance,newest,price_asc,price_desc,rating', 'page' => 'nullable|integer|min:1', 'perPage' => 'nullable|integer|min:1|max:24']);
        $start = microtime(true);
        $query = Listing::publiclyVisible()->with(['owner', 'facilities', 'images']);
        $query->when($data['city'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('city', 'like', "%$v%")->orWhere('public_area', 'like', "%$v%")))->when($data['type'] ?? null, fn ($q, $v) => $q->where('property_type', $v))->when($data['gender'] ?? null, fn ($q, $v) => $q->where('gender_rule', $v))->when($data['minPrice'] ?? null, fn ($q, $v) => $q->where('monthly_price_lkr', '>=', $v))->when($data['maxPrice'] ?? null, fn ($q, $v) => $q->where('monthly_price_lkr', '<=', $v))->when($data['minRating'] ?? null, fn ($q, $v) => $q->where('average_rating', '>=', $v))->when($data['occupancy'] ?? null, fn ($q, $v) => $q->where('occupancy_limit', '>=', $v))->when(isset($data['furnished']), fn ($q) => $q->where('furnished', $data['furnished']))->when($data['facility'] ?? null, fn ($q, $v) => $q->whereHas('facilities', fn ($f) => $f->where('name', $v)));
        $eligible = $query->limit(500)->get();
        $text = (string) ($data['q'] ?? '');
        $ranked = $text !== '' ? $ai->search($text, $eligible->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'description' => $l->description, 'area' => $l->public_area, 'city' => $l->city, 'facilities' => $l->facilities->pluck('name')->all()])->all()) : ['online' => true, 'mode' => 'structured', 'results' => $eligible->map(fn ($l) => ['id' => $l->id, 'score' => 1])->all()];
        $scores = collect($ranked['results'] ?? [])->pluck('score', 'id');
        $ordered = $eligible->filter(fn ($l) => $scores->has($l->id))->sortByDesc(fn ($l) => $scores[$l->id])->values();
        $ordered = match ($data['sort'] ?? 'relevance') {
            'newest' => $ordered->sortByDesc('published_at')->values(),
            'price_asc' => $ordered->sortBy('monthly_price_lkr')->values(),
            'price_desc' => $ordered->sortByDesc('monthly_price_lkr')->values(),
            'rating' => $ordered->sortByDesc('average_rating')->values(),
            default => $ordered,
        };
        $page = max(1, (int) ($data['page'] ?? 1));
        $per = min(24, (int) ($data['perPage'] ?? 12));
        $latency = (int) round((microtime(true) - $start) * 1000);
        SearchLog::create(['user_id' => $request->user()?->id, 'sanitized_query' => $text, 'filters' => collect($data)->except(['q', 'page', 'perPage'])->all(), 'result_count' => $ordered->count(), 'mode' => $ranked['mode'] ?? 'semantic', 'latency_ms' => $latency]);

        return response()->json(['data' => ListingResource::collection($ordered->slice(($page - 1) * $per, $per)->values()), 'meta' => ['page' => $page, 'perPage' => $per, 'total' => $ordered->count(), 'totalPages' => (int) ceil($ordered->count() / $per)], 'search' => ['mode' => $ranked['mode'] ?? 'semantic', 'aiOnline' => $ranked['online'], 'warning' => $ranked['warning'] ?? null, 'latencyMs' => $latency]]);
    }
}
