<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingAiRiskAssessment;
use Illuminate\Support\Str;

class ListingRiskService
{
    public const VERSION = 'transparent-risk-rules-1.1.0';

    public function __construct(private readonly ListingImageQualityService $imageQuality) {}

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
        $qualityScores = $listing->images->pluck('quality_score')->filter(fn ($score) => $score !== null);
        if ($qualityScores->isNotEmpty() && $qualityScores->avg() < 60) {
            $flags[] = ['code' => 'weak_image_evidence', 'severity' => 'medium', 'message' => 'Uploaded photos are too dark, compressed or low-detail for confident moderation.'];
            $evidence['imageQuality'] = ['averageScore' => round($qualityScores->avg()), 'analyzedImages' => $qualityScores->count()];
            $score += 18;
        }
        $perceptualHashes = $listing->images->pluck('perceptual_hash')->filter();
        if ($perceptualHashes->isNotEmpty()) {
            $nearDuplicateIds = \DB::table('listing_images')->where('listing_id', '!=', $listing->id)->whereNotNull('perceptual_hash')->get(['id', 'perceptual_hash'])
                ->filter(fn ($image) => $perceptualHashes->contains(fn ($hash) => ($this->imageQuality->hammingDistance($hash, $image->perceptual_hash) ?? 99) <= 5))
                ->pluck('id')->values();
            if ($nearDuplicateIds->isNotEmpty()) {
                $flags[] = ['code' => 'visually_similar_image', 'severity' => 'high', 'message' => 'A visually similar photo appears on another listing.'];
                $evidence['nearDuplicateImageIds'] = $nearDuplicateIds->take(10)->all();
                $score += 28;
            }
        }
        if (mb_strlen(trim($listing->description)) < 100) {
            $flags[] = ['code' => 'thin_description', 'severity' => 'low', 'message' => 'Description has too little evidence for a confident quality check.'];
            $score += 8;
        }
        $publicCopy = trim($listing->title.' '.$listing->description.' '.($listing->house_rules ?? ''));
        if (preg_match('/(?:\+?94|0)7\d[\s-]?\d{3}[\s-]?\d{4}|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $publicCopy)) {
            $flags[] = ['code' => 'off_platform_contact', 'severity' => 'medium', 'message' => 'Public listing text appears to contain a phone number or email address.'];
            $evidence['contactExposure'] = ['detected' => true, 'detail' => 'Contact data is never copied into this assessment.'];
            $score += 18;
        }
        if (preg_match('/https?:\/\/|www\.|(?:wa\.me|t\.me)\//iu', $publicCopy)) {
            $flags[] = ['code' => 'external_contact_link', 'severity' => 'medium', 'message' => 'Public listing text includes an external link that needs a human safety review.'];
            $score += 16;
        }
        if (preg_match('/(?:send\s+money|bank\s+transfer|pay\s+(?:an?\s+)?(?:advance|deposit))[^.]{0,60}(?:before\s+(?:viewing|visit)|to\s+reserve)|non[-\s]?refundable\s+(?:booking|reservation)\s+fee/iu', $publicCopy)) {
            $flags[] = ['code' => 'unsafe_payment_instruction', 'severity' => 'high', 'message' => 'Text may request payment before an in-person viewing or describe a non-refundable booking fee.'];
            $score += 32;
        }
        if ((int) $listing->deposit_lkr > max(1, (int) $listing->monthly_price_lkr) * 6) {
            $flags[] = ['code' => 'extreme_deposit', 'severity' => 'high', 'message' => 'The refundable deposit exceeds six months of listed rent.'];
            $evidence['depositRatio'] = round((int) $listing->deposit_lkr / max(1, (int) $listing->monthly_price_lkr), 2);
            $score += 30;
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
