<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingAiRiskAssessment;
use Illuminate\Support\Str;

class ListingRiskService
{
    public const VERSION = 'transparent-risk-rules-1.0.0';

    public function assess(Listing $listing): ListingAiRiskAssessment
    {
        $listing->loadMissing('images');
        $flags = [];
        $evidence = [];
        $score = 0;
        $peerQuery = Listing::query()->whereKeyNot($listing->id)->where('city', $listing->city)->where('property_type', $listing->property_type);
        $peerPrices = $peerQuery->pluck('monthly_price_lkr')->map(fn ($value) => (int) $value)->sort()->values();
        if ($peerPrices->count() >= 3) {
            $median = (int) $peerPrices->get((int) floor(($peerPrices->count() - 1) / 2));
            $ratio = $median > 0 ? ((int) $listing->monthly_price_lkr / $median) : 1;
            if ($ratio < 0.45 || $ratio > 2.2) {
                $flags[] = ['code' => 'price_outlier', 'severity' => 'medium', 'message' => 'Price is unusually different from comparable listings.'];
                $evidence['price'] = ['listing' => (int) $listing->monthly_price_lkr, 'peerMedian' => $median, 'peerCount' => $peerPrices->count()];
                $score += 24;
            }
        }
        $normalizedTitle = $this->normalize($listing->title);
        $normalizedDescription = $this->normalize($listing->description);
        $duplicates = Listing::query()->whereKeyNot($listing->id)->get(['id', 'title', 'description', 'latitude', 'longitude'])->filter(function ($candidate) use ($normalizedTitle, $normalizedDescription, $listing): bool {
            similar_text($normalizedDescription, $this->normalize($candidate->description), $descriptionSimilarity);
            $sameTitle = $normalizedTitle !== '' && $normalizedTitle === $this->normalize($candidate->title);
            $sameCoordinate = round((float) $candidate->latitude, 5) === round((float) $listing->latitude, 5)
                && round((float) $candidate->longitude, 5) === round((float) $listing->longitude, 5);

            return $sameTitle || $descriptionSimilarity >= 88 || $sameCoordinate;
        })->pluck('id')->values();
        if ($duplicates->isNotEmpty()) {
            $flags[] = ['code' => 'possible_duplicate', 'severity' => 'high', 'message' => 'Content or coordinates resemble another listing.'];
            $evidence['similarListingIds'] = $duplicates->take(10)->all();
            $score += 36;
        }
        $hashes = $listing->images->mapWithKeys(function ($image): array {
            $path = public_path(ltrim((string) $image->storage_path, '/'));

            return is_file($path) ? [$image->id => hash_file('sha256', $path)] : [];
        });
        if ($hashes->isNotEmpty()) {
            $otherPaths = \DB::table('listing_images')->where('listing_id', '!=', $listing->id)->pluck('storage_path', 'id');
            $duplicateImageIds = $otherPaths->filter(function ($path) use ($hashes): bool {
                $absolute = public_path(ltrim((string) $path, '/'));

                return is_file($absolute) && $hashes->contains(hash_file('sha256', $absolute));
            })->keys()->values();
            if ($duplicateImageIds->isNotEmpty()) {
                $flags[] = ['code' => 'reused_image', 'severity' => 'high', 'message' => 'An identical image appears on another listing.'];
                $evidence['duplicateImageIds'] = $duplicateImageIds->take(10)->all();
                $score += 32;
            }
        }
        if (mb_strlen(trim($listing->description)) < 100) {
            $flags[] = ['code' => 'thin_description', 'severity' => 'low', 'message' => 'Description has too little evidence for a confident quality check.'];
            $score += 8;
        }

        return ListingAiRiskAssessment::updateOrCreate(
            ['listing_id' => $listing->id],
            ['risk_score' => min(100, $score), 'flags' => $flags, 'evidence' => $evidence, 'model_version' => self::VERSION, 'status' => 'complete', 'assessed_at' => now()]
        );
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))) ?? '');
    }
}
