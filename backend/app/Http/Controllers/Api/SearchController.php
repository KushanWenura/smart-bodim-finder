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
        $query = Listing::publiclyVisible()->with(['owner.ownerProfile', 'facilities', 'images', 'nearbyPlaces']);
        $interpreted = [];
        $destinationResolution = $proximity->resolution($message);
        if ($destinationResolution['status'] === 'ambiguous') {
            $suggestions = $destinationResolution['suggestions']->map(fn ($place) => [
                'id' => $place->id,
                'name' => $place->name,
                'branchName' => $place->branch_name,
                'query' => $message.' — use '.$place->name.' as the exact destination',
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
        $facilityPatterns = [
            'WiFi' => '/\bwi[\s-]?fi\b/i',
            'Parking' => '/\b(?:parking|car\s*park(?:ing)?|vehicle\s*parking)\b/i',
            'Meals' => '/\b(?:meals?|food\s+provided)\b/i',
            'Kitchen access' => '/\b(?:kitchen|cooking\s+facilit(?:y|ies))\b/i',
            'Attached bathroom' => '/\b(?:attached|private|ensuite)\s+bathroom\b/i',
            'Hot water' => '/\bhot\s+water\b/i',
            'Air conditioning' => '/\b(?:a\s*\/?\s*c|air[\s-]*condition(?:ing|ed)?|aircon)\b/i',
            'Security/CCTV' => '/\b(?:cctv|security\s+camera)\b/i',
            'Study area' => '/\b(?:study\s+(?:area|space|desk))\b/i',
        ];
        $facilities = collect($facilityPatterns)->filter(fn ($pattern) => preg_match($pattern, $message) === 1)->keys()->values();
        foreach ($facilities as $facility) {
            $query->whereHas('facilities', fn ($builder) => $builder->where('name', $facility));
        }
        if ($facilities->isNotEmpty()) {
            $interpreted['facilities'] = $facilities->all();
        }

        $eligible = $query->limit(200)->get();
        $radiusKm = null;
        if ($destination) {
            $radiusKm = 15.0;
            if (preg_match('/(?:within|inside|less than|under)\s*([0-9]+(?:\.[0-9]+)?)\s*km/i', $message, $distanceMatch)) {
                $radiusKm = min(50, max(1, (float) $distanceMatch[1]));
            }
            $eligible = $proximity->annotate($eligible, $destination)->filter(fn ($listing) => (float) $listing->distance_km <= $radiusKm)->values();
            $interpreted['radiusKm'] = $radiusKm;
        }
        $ranked = $ai->search($message, $eligible->map(fn ($listing) => ['id' => $listing->id, 'title' => $listing->title, 'description' => $listing->description, 'area' => $listing->public_area, 'city' => $listing->city, 'facilities' => $listing->facilities->pluck('name')->all()])->all(), 50);
        $scores = collect($ranked['results'] ?? [])->pluck('score', 'id');
        if ($scores->isEmpty() && $eligible->isNotEmpty()) {
            $ranked['mode'] = 'structured-nearby-fallback';
        }
        $ordered = $eligible->map(function (Listing $listing) use ($scores, $destination, $radiusKm, $facilities, $interpreted): Listing {
            $ranking = $this->assistantRanking($listing, (float) ($scores[$listing->id] ?? 0.35), $destination?->name, $destination ? $radiusKm : null, $facilities->all(), $interpreted);
            foreach ($ranking as $attribute => $value) {
                $listing->setAttribute($attribute, $value);
            }

            return $listing;
        })->sortByDesc('match_score')->values();
        $ordered->each(function (Listing $listing, int $index): void {
            $rank = $index + 1;
            $listing->setAttribute('match_rank', $rank);
            $listing->setAttribute('match_label', $rank === 1 ? 'Best match' : ($rank <= 3 ? 'Top match' : 'Strong match'));
        });
        $results = $ordered->take(5);
        $locationText = $destination ? ' near '.$destination->name : (isset($interpreted['location']) ? ' around '.$interpreted['location'] : '');
        $requirements = $this->requirementLabels($interpreted);
        $answer = $results->isEmpty()
            ? "I couldn't find a published place matching every required facility, budget and location rule. Try a wider radius, a slightly higher budget, or remove one must-have facility."
            : 'I found '.$results->count().' exact eligible '.str('match')->plural($results->count()).$locationText.'. Every result passes your hard requirements; the badges rank suitability using distance, semantic fit, rating, value, verified ownership and nearby essentials.';
        SearchLog::create(['user_id' => $request->user()?->id, 'sanitized_query' => $message, 'filters' => $interpreted, 'result_count' => $results->count(), 'mode' => 'assistant:'.($ranked['mode'] ?? 'semantic'), 'latency_ms' => 0]);

        return response()->json(['answer' => $answer, 'results' => ListingResource::collection($results), 'requirements' => $requirements, 'interpreted' => $interpreted, 'search' => ['mode' => $ranked['mode'] ?? 'semantic', 'aiOnline' => $ranked['online'] ?? false, 'warning' => $ranked['warning'] ?? null, 'rankingMethod' => 'strict filters followed by weighted suitability scoring'], 'disclaimer' => 'Suitability scores compare only eligible published listings; they are not guarantees. Verify the property, facilities and route in person before paying; exact addresses remain private.']);
    }

    private function assistantRanking(Listing $listing, float $semanticScore, ?string $destinationName, ?float $radiusKm, array $facilities, array $interpreted): array
    {
        $semantic = max(0.0, min(1.0, $semanticScore));
        $distance = (float) ($listing->getAttribute('distance_km') ?? 0);
        $distanceFit = $radiusKm ? max(0.0, 1 - ($distance / $radiusKm)) : 0.65;
        $ratingFit = max(0.0, min(1.0, ((float) $listing->average_rating) / 5));
        $maxPrice = isset($interpreted['maxPrice']) ? (int) $interpreted['maxPrice'] : null;
        $budgetHeadroom = $maxPrice ? max(0.0, min(1.0, ($maxPrice - (int) $listing->monthly_price_lkr) / $maxPrice)) : 0.5;
        $valueFit = 0.5 + ($budgetHeadroom * 0.5);
        $verified = ($listing->owner?->ownerProfile?->verification_status ?? null) === 'verified';
        $nearbyTypes = $listing->nearbyPlaces->pluck('type')->unique()->count();
        $nearbyFit = min(1.0, $nearbyTypes / 5);
        $score = (int) round(35 + ($semantic * 20) + ($distanceFit * 20) + ($ratingFit * 10) + ($valueFit * 5) + (($verified ? 1 : 0.6) * 5) + ($nearbyFit * 5));
        $matched = collect($facilities)->map(fn ($facility) => $facility.' included');
        if ($maxPrice) {
            $matched->push('Under Rs. '.number_format($maxPrice));
        }
        if ($destinationName && $radiusKm) {
            $matched->push('Within '.rtrim(rtrim(number_format($radiusKm, 1), '0'), '.').' km of '.$destinationName);
        }
        if (isset($interpreted['propertyType'])) {
            $matched->push(str((string) $interpreted['propertyType'])->replace('_', ' ')->title()->toString());
        }
        if (isset($interpreted['gender'])) {
            $matched->push($interpreted['gender'] === 'female_only' ? 'Female only' : 'Male only');
        }
        $reasons = collect();
        if ($facilities !== []) {
            $reasons->push('Includes all '.count($facilities).' requested facilities');
        }
        if ($destinationName) {
            $reasons->push(number_format($distance, 1).' km from '.$destinationName);
        }
        if ($maxPrice) {
            $reasons->push('Rs. '.number_format($maxPrice - (int) $listing->monthly_price_lkr).' below budget');
        }
        if ($verified) {
            $reasons->push('Verified property owner');
        }
        if ((float) $listing->average_rating > 0) {
            $reasons->push(number_format((float) $listing->average_rating, 1).'/5 resident rating');
        }

        return [
            'match_score' => min(99, max(1, $score)),
            'matched_requirements' => $matched->values()->all(),
            'match_reasons' => $reasons->take(4)->values()->all(),
        ];
    }

    private function requirementLabels(array $interpreted): array
    {
        $requirements = collect($interpreted['facilities'] ?? []);
        if (isset($interpreted['maxPrice'])) {
            $requirements->push('Max Rs. '.number_format((int) $interpreted['maxPrice']));
        }
        if (isset($interpreted['radiusKm'], $interpreted['destination']['name'])) {
            $requirements->push('Within '.rtrim(rtrim(number_format((float) $interpreted['radiusKm'], 1), '0'), '.').' km of '.$interpreted['destination']['name']);
        }
        if (isset($interpreted['propertyType'])) {
            $requirements->push(str((string) $interpreted['propertyType'])->replace('_', ' ')->title()->toString());
        }
        if (isset($interpreted['gender'])) {
            $requirements->push($interpreted['gender'] === 'female_only' ? 'Female only' : 'Male only');
        }
        if (isset($interpreted['location'])) {
            $requirements->push('Around '.$interpreted['location']);
        }

        return $requirements->values()->all();
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
