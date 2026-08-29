<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\SearchLog;
use App\Services\AiServiceClient;
use App\Services\PersonalizationService;
use App\Services\ProximityService;
use App\Services\QueryUnderstandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function assistant(Request $request, AiServiceClient $ai, ProximityService $proximity, QueryUnderstandingService $understanding, PersonalizationService $personalization): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|min:2|max:500',
            'context' => 'sometimes|array|max:3',
            'context.*' => 'string|min:2|max:500',
        ]);
        $message = trim($data['message']);
        $context = collect($data['context'] ?? [])->map(fn ($item) => trim((string) $item))->filter()->values();
        $interpretationText = $this->isRefinement($message) && $context->isNotEmpty()
            ? trim($context->implode(' ').' '.$message)
            : $message;
        $normalized = mb_strtolower($interpretationText);
        $query = Listing::publiclyVisible()->with(['owner.ownerProfile', 'facilities', 'images', 'nearbyPlaces']);
        $intent = $understanding->understand($interpretationText);
        $interpreted = [
            'language' => $intent['language'],
            'confidence' => $intent['confidence'],
        ];
        $destinationResolution = $proximity->resolution($interpretationText);
        if ($destinationResolution['status'] === 'ambiguous') {
            $suggestions = $destinationResolution['suggestions']->map(fn ($place) => [
                'id' => $place->id,
                'name' => $place->name,
                'branchName' => $place->branch_name,
                'query' => $interpretationText.' — use '.$place->name.' as the exact destination',
            ])->values();
            $organization = $destinationResolution['organization'] ?? 'That institution';
            $interpreted['destination'] = ['status' => 'ambiguous', 'organization' => $organization];
            $log = SearchLog::create(['user_id' => $request->user()?->id, 'sanitized_query' => $message, 'filters' => $interpreted, 'intent_confidence' => $intent['confidence'], 'result_count' => 0, 'mode' => 'assistant:branch-clarification', 'model_version' => 'query-understanding-v2', 'experiment_bucket' => 'ranking-v2', 'latency_ms' => 0]);

            return response()->json([
                'answer' => $organization.' has several branches in Sri Lanka. I will not choose one automatically—select the exact branch you travel to.',
                'results' => [],
                'suggestions' => $suggestions,
                'interpreted' => $interpreted,
                'search' => ['mode' => 'branch-clarification', 'aiOnline' => true, 'warning' => null, 'searchLogId' => $log->id],
                'followUps' => [],
                'understanding' => $intent,
                'disclaimer' => 'Choose the correct branch so distance and nearby-place calculations use the right coordinates.',
            ]);
        }
        $destination = $destinationResolution['destination'];
        if ($destination) {
            $interpreted['destination'] = ['name' => $destination->name, 'type' => $destination->type, 'organizationName' => $destination->organization_name, 'branchName' => $destination->branch_name];
        }

        // Query Understanding v2 is authoritative for multilingual and
        // hard/preferred/excluded slots. The legacy English rules below remain
        // as a conservative compatibility fallback.
        foreach (['minPrice', 'maxPrice', 'preferredMaxPrice', 'propertyType', 'preferredPropertyType', 'excludedPropertyTypes', 'gender', 'occupancy', 'furnished', 'preferredFurnished'] as $slot) {
            if (array_key_exists($slot, $intent)) {
                $interpreted[$slot] = $intent[$slot];
            }
        }
        if (isset($intent['minPrice'], $intent['maxPrice'])) {
            $query->whereBetween('monthly_price_lkr', [(int) $intent['minPrice'], (int) $intent['maxPrice']]);
        } elseif (isset($intent['maxPrice'])) {
            $query->where('monthly_price_lkr', '<=', (int) $intent['maxPrice']);
        }
        if (isset($intent['propertyType'])) {
            $query->where('property_type', $intent['propertyType']);
        }
        if (! empty($intent['excludedPropertyTypes'])) {
            $query->whereNotIn('property_type', $intent['excludedPropertyTypes']);
        }
        if (isset($intent['gender'])) {
            $query->where('gender_rule', $intent['gender']);
        }
        if (isset($intent['occupancy'])) {
            $query->where('occupancy_limit', '>=', (int) $intent['occupancy']);
        }
        if (array_key_exists('furnished', $intent)) {
            $query->where('furnished', (bool) $intent['furnished']);
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
        if (! $destination && ! isset($interpreted['location']) && ($intent['needsDestination'] ?? false)) {
            $suggestions = $proximity->destinations()->take(8)->map(fn ($place) => [
                'id' => $place->id,
                'name' => $place->name,
                'branchName' => $place->branch_name,
                'query' => $interpretationText.' near '.$place->name,
            ])->values();
            $log = SearchLog::create(['user_id' => $request->user()?->id, 'sanitized_query' => $message, 'filters' => $interpreted, 'intent_confidence' => $intent['confidence'], 'result_count' => 0, 'mode' => 'assistant:destination-needed', 'model_version' => 'query-understanding-v2', 'experiment_bucket' => 'ranking-v2', 'latency_ms' => 0]);

            return response()->json([
                'answer' => 'I understood the room requirements, but I need the exact campus, workplace or town before calculating a trustworthy commute.',
                'results' => [],
                'suggestions' => $suggestions,
                'followUps' => [],
                'requirements' => $this->requirementLabels($interpreted),
                'interpreted' => $interpreted,
                'understanding' => $intent,
                'search' => ['mode' => 'destination-clarification', 'aiOnline' => true, 'searchLogId' => $log->id],
                'disclaimer' => 'Buddy will not guess a destination because different branches produce different distance and transport results.',
            ]);
        }
        $rangePattern = '/(?:between|from)\s*(?:rs\.?|lkr)?\s*([0-9][0-9,.]*\s*k?)\s*(?:and|to|-)\s*(?:rs\.?|lkr)?\s*([0-9][0-9,.]*\s*k?)/i';
        $budgetPattern = '/(?:under|below|maximum|max|budget(?:\s+(?:of|to))?|less\s+than|up\s+to)\s*(?:rs\.?|lkr)?\s*([0-9][0-9,.]*\s*k?)/i';
        $budgetText = isset($intent['preferredMaxPrice']) ? '' : (preg_match($rangePattern, $message) || preg_match($budgetPattern, $message) ? $message : $interpretationText);
        if (preg_match($rangePattern, $budgetText, $rangeMatches)) {
            $first = $this->moneyValue($rangeMatches[1]);
            $second = $this->moneyValue($rangeMatches[2]);
            $minimum = min($first, $second);
            $maximum = max($first, $second);
            if ($minimum >= 5000 && $maximum >= $minimum) {
                $query->whereBetween('monthly_price_lkr', [$minimum, $maximum]);
                $interpreted['minPrice'] = $minimum;
                $interpreted['maxPrice'] = $maximum;
            }
        } elseif (preg_match($budgetPattern, $budgetText, $matches)) {
            $budget = $this->moneyValue($matches[1]);
            if ($budget >= 5000) {
                $query->where('monthly_price_lkr', '<=', $budget);
                $interpreted['maxPrice'] = $budget;
            }
        }
        if ($context->isNotEmpty() && preg_match('/\b(?:cheaper|lower\s+budget)\b/i', $message) && isset($interpreted['maxPrice']) && ! preg_match($budgetPattern, $message)) {
            $interpreted['maxPrice'] = max(5000, (int) $interpreted['maxPrice'] - 5000);
            $query->where('monthly_price_lkr', '<=', $interpreted['maxPrice']);
        }
        $propertyAliases = ['annex' => 'annex', 'studio' => 'studio', 'hostel' => 'hostel', 'shared room' => 'shared_room', 'private room' => 'private_room', 'boarding room' => 'boarding_room', 'small house' => 'small_house'];
        foreach ((isset($intent['preferredPropertyType']) || ! empty($intent['excludedPropertyTypes'])) ? [] : $propertyAliases as $needle => $type) {
            if (str_contains($normalized, $needle)) {
                $query->where('property_type', $type);
                $interpreted['propertyType'] = $type;
                break;
            }
        }
        if (preg_match('/\b(female|women|girls|ladies)\b/i', $interpretationText)) {
            $query->where('gender_rule', 'female_only');
            $interpreted['gender'] = 'female_only';
        } elseif (preg_match('/\b(male|men|boys|gents)\b/i', $interpretationText)) {
            $query->where('gender_rule', 'male_only');
            $interpreted['gender'] = 'male_only';
        }
        $facilities = collect($intent['hardFacilities'] ?? [])->unique()->values();
        foreach ($facilities as $facility) {
            $query->whereHas('facilities', fn ($builder) => $builder->where('name', $facility));
        }
        $excludedFacilities = collect($intent['excludedFacilities'] ?? [])->unique()->values();
        foreach ($excludedFacilities as $facility) {
            $query->whereDoesntHave('facilities', fn ($builder) => $builder->where('name', $facility));
        }
        if ($facilities->isNotEmpty()) {
            $interpreted['facilities'] = $facilities->all();
        }
        if (! empty($intent['preferredFacilities'])) {
            $interpreted['preferredFacilities'] = $intent['preferredFacilities'];
        }
        if ($excludedFacilities->isNotEmpty()) {
            $interpreted['excludedFacilities'] = $excludedFacilities->all();
        }
        if (preg_match('/\b(?:for|fit|suit(?:able)?\s+for)\s*([1-9]|one|two|three|four)\s*(?:people|persons?|students?|tenants?|friends?)\b/i', $interpretationText, $occupancyMatch)) {
            $occupancyWords = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4];
            $occupancy = $occupancyWords[mb_strtolower($occupancyMatch[1])] ?? (int) $occupancyMatch[1];
            $query->where('occupancy_limit', '>=', $occupancy);
            $interpreted['occupancy'] = $occupancy;
        }
        if (! array_key_exists('preferredFurnished', $intent) && preg_match('/\b(?:furnished|fully\s+furnished)\b/i', $interpretationText) && ! preg_match('/\bunfurnished\b/i', $interpretationText)) {
            $query->where('furnished', true);
            $interpreted['furnished'] = true;
        } elseif (! array_key_exists('preferredFurnished', $intent) && preg_match('/\bunfurnished\b/i', $interpretationText)) {
            $query->where('furnished', false);
            $interpreted['furnished'] = false;
        }
        $nearbyPatterns = [
            'bus_station' => '/\b(?:bus|bus\s+stop|bus\s+stand)\b/i',
            'train_station' => '/\b(?:train|railway)(?:\s+station)?\b/i',
            'supermarket' => '/\b(?:cargills?|food\s*city|supermarket|grocery)\b/i',
            'hospital' => '/\b(?:hospital|medical|clinic)\b/i',
            'food' => '/\b(?:food(?!\s*city)|restaurants?|caf[eé]s?|dining|eater(?:y|ies))\b/i',
        ];
        $nearbyPriorities = collect($intent['nearbyPriorities'] ?? [])->merge(collect($nearbyPatterns)->filter(fn ($pattern) => preg_match($pattern, $interpretationText) === 1)->keys())->unique()->values();
        if ($nearbyPriorities->isNotEmpty()) {
            $interpreted['nearbyPriorities'] = $nearbyPriorities->all();
        }
        if (preg_match('/\b(?:closest|nearest|shortest\s+commute)\b/i', $message)) {
            $interpreted['preference'] = 'closest';
        } elseif (preg_match('/\b(?:cheapest|lowest\s+price|most\s+affordable|best\s+value)\b/i', $message)) {
            $interpreted['preference'] = 'value';
        } elseif (preg_match('/\b(?:best\s+rated|highest\s+rated|top\s+rated)\b/i', $message)) {
            $interpreted['preference'] = 'rating';
        } elseif (! empty($intent['preference'])) {
            $interpreted['preference'] = $intent['preference'];
        }

        $eligible = $query->limit(200)->get();
        $radiusKm = null;
        if ($destination) {
            $radiusKm = isset($intent['radiusKm']) ? (float) $intent['radiusKm'] : 15.0;
            $radiusPattern = '/(?:within|inside|less than|under)\s*([0-9]+(?:\.[0-9]+)?)\s*km/i';
            $radiusText = preg_match($radiusPattern, $message) ? $message : $interpretationText;
            if (preg_match($radiusPattern, $radiusText, $distanceMatch)) {
                $radiusKm = min(50, max(1, (float) $distanceMatch[1]));
            }
            $eligible = $proximity->annotate($eligible, $destination, $radiusKm, 50);
            $interpreted['radiusKm'] = $radiusKm;
        }
        $ranked = $ai->search($interpretationText, $eligible->map(fn ($listing) => ['id' => $listing->id, 'title' => $listing->title, 'description' => $listing->description, 'area' => $listing->public_area, 'city' => $listing->city, 'facilities' => $listing->facilities->pluck('name')->all()])->all(), 50);
        if (isset($ranked['intent'])) {
            $intent['classifier'] = $ranked['intent'];
        }
        $scores = collect($ranked['results'] ?? [])->pluck('score', 'id');
        if ($scores->isEmpty() && $eligible->isNotEmpty()) {
            $ranked['mode'] = 'structured-nearby-fallback';
        }
        $personalPreferences = $personalization->preferences($request->user());
        $ordered = $eligible->map(function (Listing $listing) use ($scores, $destination, $radiusKm, $facilities, $interpreted, $personalPreferences, $personalization): Listing {
            $ranking = $this->assistantRanking($listing, (float) ($scores[$listing->id] ?? 0.35), $destination?->name, $destination ? $radiusKm : null, $facilities->all(), $interpreted, $personalPreferences, $personalization);
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
        $followUps = $this->followUpPrompts($message, $interpreted, $results->isEmpty());
        $relaxationAnalysis = $results->isEmpty() ? $this->relaxationAnalysis($interpreted, $destination, $proximity) : [];
        $largestBlocker = collect($relaxationAnalysis)->sortByDesc('matchesIfRelaxed')->first();
        $answer = $results->isEmpty()
            ? "I couldn't find a published place matching every hard requirement.".($largestBlocker && $largestBlocker['matchesIfRelaxed'] > 0 ? ' Relaxing only “'.$largestBlocker['constraint'].'” would recover '.$largestBlocker['matchesIfRelaxed'].' possible '.str('match')->plural($largestBlocker['matchesIfRelaxed']).'.' : '').' Choose a refinement below; I will never relax one silently.'
            : 'I found '.$results->count().' exact eligible '.str('match')->plural($results->count()).$locationText.'. The best fit is '.$results->first()->title.' at Rs. '.number_format((int) $results->first()->monthly_price_lkr).' per month. Every result passes your hard filters; the order then considers distance, meaning, rating, value, owner verification and nearby essentials.';
        $log = SearchLog::create(['user_id' => $request->user()?->id, 'sanitized_query' => $message, 'filters' => $interpreted, 'intent_confidence' => $intent['confidence'], 'result_count' => $results->count(), 'mode' => 'assistant:'.($ranked['mode'] ?? 'semantic'), 'model_version' => $ranked['modelVersion'] ?? 'structured-fallback', 'experiment_bucket' => 'ranking-v2', 'latency_ms' => 0]);

        return response()->json(['answer' => $answer, 'results' => ListingResource::collection($results), 'requirements' => $requirements, 'followUps' => $followUps, 'relaxationAnalysis' => $relaxationAnalysis, 'interpreted' => $interpreted, 'understanding' => $intent, 'search' => ['mode' => $ranked['mode'] ?? 'semantic', 'aiOnline' => $ranked['online'] ?? false, 'warning' => $ranked['warning'] ?? null, 'rankingMethod' => 'strict filters followed by weighted suitability scoring', 'rankingDetails' => 'must-have and exclusion eligibility, followed by semantic, commute, budget, reviews, nearby, preferred facilities and opt-in personalization', 'searchLogId' => $log->id, 'personalized' => ($personalPreferences['signals'] ?? 0) >= 3], 'disclaimer' => 'Suitability scores compare only eligible published listings; they are not guarantees. Verify the property, facilities and route in person before paying; exact addresses remain private.']);
    }

    private function relaxationAnalysis(array $interpreted, mixed $destination, ProximityService $proximity): array
    {
        $listings = Listing::publiclyVisible()->with('facilities')->limit(500)->get();
        $failures = [];
        foreach ($listings as $listing) {
            $listingFailures = [];
            if (isset($interpreted['maxPrice']) && (int) $listing->monthly_price_lkr > (int) $interpreted['maxPrice']) {
                $listingFailures[] = 'Max Rs. '.number_format((int) $interpreted['maxPrice']);
            }
            if (isset($interpreted['minPrice']) && (int) $listing->monthly_price_lkr < (int) $interpreted['minPrice']) {
                $listingFailures[] = 'Min Rs. '.number_format((int) $interpreted['minPrice']);
            }
            $availableFacilities = $listing->facilities->pluck('name');
            foreach ($interpreted['facilities'] ?? [] as $facility) {
                if (! $availableFacilities->contains($facility)) {
                    $listingFailures[] = $facility;
                }
            }
            foreach ($interpreted['excludedFacilities'] ?? [] as $facility) {
                if ($availableFacilities->contains($facility)) {
                    $listingFailures[] = 'Without '.$facility;
                }
            }
            if (isset($interpreted['propertyType']) && $listing->property_type !== $interpreted['propertyType']) {
                $listingFailures[] = str((string) $interpreted['propertyType'])->replace('_', ' ')->title()->toString();
            }
            if (isset($interpreted['gender']) && $listing->gender_rule !== $interpreted['gender']) {
                $listingFailures[] = $interpreted['gender'] === 'female_only' ? 'Female only' : 'Male only';
            }
            if (isset($interpreted['occupancy']) && (int) $listing->occupancy_limit < (int) $interpreted['occupancy']) {
                $listingFailures[] = 'Fits '.$interpreted['occupancy'].' people';
            }
            if (array_key_exists('furnished', $interpreted) && (bool) $listing->furnished !== (bool) $interpreted['furnished']) {
                $listingFailures[] = $interpreted['furnished'] ? 'Furnished' : 'Unfurnished';
            }
            if ($destination && isset($interpreted['radiusKm'])) {
                $distance = $proximity->distanceKm((float) $listing->latitude, (float) $listing->longitude, (float) $destination->latitude, (float) $destination->longitude);
                if ($distance > (float) $interpreted['radiusKm']) {
                    $listingFailures[] = 'Within '.rtrim(rtrim(number_format((float) $interpreted['radiusKm'], 1), '0'), '.').' km';
                }
            }
            if (! empty($interpreted['nearbyAreas']) && ! in_array($listing->public_area, $interpreted['nearbyAreas'], true)) {
                $listingFailures[] = 'Around '.$interpreted['location'];
            }
            $unique = array_values(array_unique($listingFailures));
            foreach ($unique as $constraint) {
                $failures[$constraint]['blockedListings'] = ($failures[$constraint]['blockedListings'] ?? 0) + 1;
                if (count($unique) === 1) {
                    $failures[$constraint]['matchesIfRelaxed'] = ($failures[$constraint]['matchesIfRelaxed'] ?? 0) + 1;
                }
            }
        }

        return collect($failures)->map(fn ($counts, $constraint) => [
            'constraint' => $constraint,
            'blockedListings' => (int) ($counts['blockedListings'] ?? 0),
            'matchesIfRelaxed' => (int) ($counts['matchesIfRelaxed'] ?? 0),
        ])->sortByDesc('matchesIfRelaxed')->take(6)->values()->all();
    }

    private function assistantRanking(Listing $listing, float $semanticScore, ?string $destinationName, ?float $radiusKm, array $facilities, array $interpreted, array $personalPreferences, PersonalizationService $personalization): array
    {
        $semantic = max(0.0, min(1.0, $semanticScore));
        $distance = (float) ($listing->getAttribute('distance_km') ?? 0);
        $distanceFit = $radiusKm ? max(0.0, 1 - ($distance / $radiusKm)) : 0.65;
        $ratingFit = max(0.0, min(1.0, ((float) $listing->average_rating) / 5));
        $maxPrice = isset($interpreted['maxPrice']) ? (int) $interpreted['maxPrice'] : null;
        $targetPrice = $maxPrice ?? (isset($interpreted['preferredMaxPrice']) ? (int) $interpreted['preferredMaxPrice'] : null);
        $budgetHeadroom = $targetPrice ? max(0.0, min(1.0, ($targetPrice - (int) $listing->monthly_price_lkr) / $targetPrice)) : 0.5;
        $valueFit = 0.5 + ($budgetHeadroom * 0.5);
        $verified = ($listing->owner?->ownerProfile?->verification_status ?? null) === 'verified';
        $nearbyTypes = $listing->nearbyPlaces->pluck('type')->unique()->count();
        $nearbyFit = min(1.0, $nearbyTypes / 9);
        $nearbyPriorities = collect($interpreted['nearbyPriorities'] ?? []);
        $priorityPlaces = $listing->nearbyPlaces->whereIn('type', $nearbyPriorities);
        $priorityFit = $nearbyPriorities->isEmpty()
            ? 0.0
            : $priorityPlaces->avg(fn ($place) => max(0.0, 1 - (((int) $place->distance_m) / 5000))) ?? 0.0;
        $preferredFacilities = collect($interpreted['preferredFacilities'] ?? []);
        $softFits = collect();
        if ($preferredFacilities->isNotEmpty()) {
            $softFits->push($preferredFacilities->intersect($listing->facilities->pluck('name'))->count() / $preferredFacilities->count());
        }
        if (isset($interpreted['preferredPropertyType'])) {
            $softFits->push($listing->property_type === $interpreted['preferredPropertyType'] ? 1.0 : 0.0);
        }
        if (array_key_exists('preferredFurnished', $interpreted)) {
            $softFits->push((bool) $listing->furnished === (bool) $interpreted['preferredFurnished'] ? 1.0 : 0.0);
        }
        if (isset($interpreted['preferredMaxPrice'])) {
            $softFits->push((int) $listing->monthly_price_lkr <= (int) $interpreted['preferredMaxPrice'] ? 1.0 : $valueFit * .5);
        }
        $preferredFit = (float) ($softFits->avg() ?? 0.0);
        $safetyFit = $listing->facilities->pluck('name')->contains('Security/CCTV') ? 1.0 : 0.5;
        $preferenceBoost = match ($interpreted['preference'] ?? null) {
            'closest' => $distanceFit * 6,
            'value' => $valueFit * 6,
            'rating' => $ratingFit * 6,
            'safety' => $safetyFit * 6,
            default => 3,
        };
        $personal = $personalization->listingBoost($listing, $personalPreferences);
        $score = (int) round(27 + ($semantic * 18) + ($distanceFit * 17) + ($ratingFit * 8) + ($valueFit * 6) + (($verified ? 1 : 0.6) * 5) + ($nearbyFit * 4) + ($priorityFit * 6) + ($preferredFit * 6) + $preferenceBoost + $personal['score']);
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
        if (isset($interpreted['occupancy'])) {
            $matched->push('Fits '.$interpreted['occupancy'].' people');
        }
        if (array_key_exists('furnished', $interpreted)) {
            $matched->push($interpreted['furnished'] ? 'Furnished' : 'Unfurnished');
        }
        foreach ($priorityPlaces as $place) {
            $matched->push('Near '.$place->name);
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
        if ($priorityPlaces->isNotEmpty()) {
            $nearestPriority = $priorityPlaces->sortBy('distance_m')->first();
            $reasons->push(number_format(((int) $nearestPriority->distance_m) / 1000, 1).' km to '.$nearestPriority->name);
        }
        if ($preferredFit > 0) {
            $reasons->push('Fits '.(int) round($preferredFit * 100).'% of your optional preferences');
        }
        foreach ($personal['reasons'] as $reason) {
            $reasons->push($reason);
        }

        return [
            'match_score' => min(99, max(1, $score)),
            'matched_requirements' => $matched->values()->all(),
            'match_reasons' => $reasons->take(4)->values()->all(),
            'match_breakdown' => [
                'semantic' => (int) round($semantic * 100),
                'commute' => (int) round($distanceFit * 100),
                'budgetValue' => (int) round($valueFit * 100),
                'residentEvidence' => (int) round($ratingFit * 100),
                'nearbyEssentials' => (int) round(max($nearbyFit, $priorityFit) * 100),
                'preferredFacilities' => (int) round($preferredFit * 100),
                'personalizationBoost' => round((float) $personal['score'], 1),
            ],
        ];
    }

    private function requirementLabels(array $interpreted): array
    {
        $requirements = collect($interpreted['facilities'] ?? []);
        foreach ($interpreted['preferredFacilities'] ?? [] as $facility) {
            $requirements->push('Prefer '.$facility);
        }
        foreach ($interpreted['excludedFacilities'] ?? [] as $facility) {
            $requirements->push('Without '.$facility);
        }
        if (isset($interpreted['preferredMaxPrice'])) {
            $requirements->push('Prefer under Rs. '.number_format((int) $interpreted['preferredMaxPrice']));
        }
        if (isset($interpreted['preferredPropertyType'])) {
            $requirements->push('Prefer '.str((string) $interpreted['preferredPropertyType'])->replace('_', ' ')->title()->toString());
        }
        if (! empty($interpreted['excludedPropertyTypes'])) {
            foreach ($interpreted['excludedPropertyTypes'] as $type) {
                $requirements->push('Not '.str((string) $type)->replace('_', ' ')->title()->toString());
            }
        }
        if (array_key_exists('preferredFurnished', $interpreted)) {
            $requirements->push($interpreted['preferredFurnished'] ? 'Prefer furnished' : 'Prefer unfurnished');
        }
        if (isset($interpreted['maxPrice'])) {
            $requirements->push('Max Rs. '.number_format((int) $interpreted['maxPrice']));
        }
        if (isset($interpreted['minPrice'])) {
            $requirements->push('Min Rs. '.number_format((int) $interpreted['minPrice']));
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
        if (isset($interpreted['occupancy'])) {
            $requirements->push('Fits '.$interpreted['occupancy'].' people');
        }
        if (array_key_exists('furnished', $interpreted)) {
            $requirements->push($interpreted['furnished'] ? 'Furnished' : 'Unfurnished');
        }
        $nearbyLabels = ['bus_station' => 'Prefer nearby bus transport', 'train_station' => 'Prefer a nearby railway station', 'supermarket' => 'Prefer nearby Cargills or a supermarket', 'hospital' => 'Prefer nearby healthcare', 'pharmacy' => 'Prefer a nearby pharmacy', 'food' => 'Prefer nearby food options', 'bank_atm' => 'Prefer a nearby bank or ATM', 'police' => 'Prefer a nearby police station', 'laundry' => 'Prefer nearby laundry'];
        foreach ($interpreted['nearbyPriorities'] ?? [] as $type) {
            $requirements->push($nearbyLabels[$type] ?? 'Near '.str($type)->replace('_', ' '));
        }

        return $requirements->unique()->values()->all();
    }

    private function isRefinement(string $message): bool
    {
        return preg_match('/^(?:also|same|make|show|only|closer|cheaper|add|remove|change|increase|wider|within|what about|how about)\b/i', trim($message)) === 1;
    }

    private function moneyValue(string $raw): int
    {
        $normalized = mb_strtolower(trim($raw));
        $thousands = str_ends_with($normalized, 'k');
        $number = (float) str_replace([',', ' ', 'k'], '', $normalized);

        return (int) round($number * ($thousands ? 1000 : 1));
    }

    private function followUpPrompts(string $message, array $interpreted, bool $empty): array
    {
        $prompts = collect();
        $facilities = collect($interpreted['facilities'] ?? []);
        $radius = isset($interpreted['radiusKm']) ? (float) $interpreted['radiusKm'] : null;
        $budget = isset($interpreted['maxPrice']) ? (int) $interpreted['maxPrice'] : null;

        if ($empty) {
            if ($facilities->isNotEmpty()) {
                $removed = $facilities->last();
                $prompts->push(['name' => 'Try without '.$removed, 'query' => $this->assistantQuery($interpreted, ['facilities' => $facilities->slice(0, -1)->values()->all()])]);
            }
            if ($radius) {
                $wider = min(50, max($radius + 5, $radius * 1.5));
                $prompts->push(['name' => 'Widen to '.rtrim(rtrim(number_format($wider, 1), '0'), '.').' km', 'query' => $this->assistantQuery($interpreted, ['radiusKm' => $wider])]);
            }
            if ($budget) {
                $prompts->push(['name' => 'Increase budget to Rs. '.number_format($budget + 5000), 'query' => $this->assistantQuery($interpreted, ['maxPrice' => $budget + 5000])]);
            }
            $prompts->push(['name' => 'Browse the closest available stays', 'query' => $this->assistantQuery($interpreted, ['facilities' => [], 'maxPrice' => null]).' closest first']);
        } else {
            if ($radius && $radius > 5) {
                $prompts->push(['name' => 'Only within '.max(5, (int) $radius - 5).' km', 'query' => $this->assistantQuery($interpreted, ['radiusKm' => max(5, $radius - 5)]).' closest first']);
            }
            if ($budget && $budget > 15000) {
                $prompts->push(['name' => 'Cheaper than Rs. '.number_format($budget - 5000), 'query' => $this->assistantQuery($interpreted, ['maxPrice' => $budget - 5000]).' best value']);
            }
            if (! $facilities->contains('Attached bathroom')) {
                $prompts->push(['name' => 'Add a private bathroom', 'query' => $this->assistantQuery($interpreted, ['facilities' => $facilities->push('Attached bathroom')->unique()->values()->all()])]);
            }
            $prompts->push(['name' => 'Prioritize the best rating', 'query' => $this->assistantQuery($interpreted).' best rated']);
        }

        return $prompts->take(4)->values()->all();
    }

    private function assistantQuery(array $interpreted, array $overrides = []): string
    {
        $filters = array_merge($interpreted, $overrides);
        $parts = ['Find a room'];
        if (isset($filters['destination']['name'])) {
            $parts[] = 'near '.$filters['destination']['name'];
        } elseif (isset($filters['location'])) {
            $parts[] = 'around '.$filters['location'];
        }
        if (! empty($filters['radiusKm'])) {
            $parts[] = 'within '.rtrim(rtrim(number_format((float) $filters['radiusKm'], 1), '0'), '.').' km';
        }
        if (! empty($filters['facilities'])) {
            $parts[] = 'with '.implode(', ', $filters['facilities']);
        }
        if (! empty($filters['maxPrice'])) {
            $parts[] = 'under Rs. '.number_format((int) $filters['maxPrice']);
        }
        if (! empty($filters['occupancy'])) {
            $parts[] = 'for '.$filters['occupancy'].' people';
        }
        if (($filters['furnished'] ?? null) === true) {
            $parts[] = 'furnished';
        }
        if (isset($filters['gender'])) {
            $parts[] = $filters['gender'] === 'female_only' ? 'female only' : 'male only';
        }

        return implode(' ', $parts);
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
