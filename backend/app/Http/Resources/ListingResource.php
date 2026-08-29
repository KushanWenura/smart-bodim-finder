<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingResource extends JsonResource
{
    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return str_starts_with($path, '/')
            ? asset(ltrim($path, '/'))
            : asset('storage/'.$path);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'slug' => $this->public_slug, 'title' => $this->title, 'description' => $this->description, 'propertyType' => $this->property_type,
            'price' => $this->monthly_price_lkr, 'deposit' => $this->deposit_lkr, 'area' => $this->public_area, 'city' => $this->city, 'district' => $this->district,
            'privateAddress' => $this->when($request->user() && ($request->user()->role === 'admin' || $request->user()->id === $this->owner_id), fn () => $this->private_address),
            'latitude' => (float) $this->latitude, 'longitude' => (float) $this->longitude, 'genderRule' => $this->gender_rule, 'occupancy' => $this->occupancy_limit,
            'sharingAllowed' => (bool) $this->sharing_allowed, 'available' => (bool) $this->available, 'availableFrom' => $this->available_from?->toDateString(), 'furnished' => (bool) $this->furnished,
            'availabilityStatus' => $this->resource->getAttribute('availability_status') ?? ($this->available ? 'available' : 'unavailable'),
            'availabilityLabel' => $this->resource->getAttribute('availability_label') ?? ($this->available ? 'Available for enquiries and viewings' : 'Not currently available'),
            'nextAvailableFrom' => $this->resource->getAttribute('next_available_from') ?? $this->available_from?->toDateString(),
            'holdExpiresAt' => $this->when($this->resource->getAttribute('hold_expires_at') !== null, fn () => $this->resource->getAttribute('hold_expires_at')),
            'houseRules' => $this->house_rules, 'status' => $this->status, 'moderationFeedback' => $this->moderation_feedback, 'rating' => (float) $this->average_rating,
            'reviewCount' => $this->review_count, 'favoriteCount' => $this->favorite_count, 'viewCount' => $this->view_count, 'publishedAt' => $this->published_at,
            'ownerName' => $this->whenLoaded('owner', fn () => $this->owner->name), 'ownerVerified' => $this->whenLoaded('owner', fn () => ($this->owner->ownerProfile?->verification_status ?? null) === 'verified'),
            'facilities' => $this->whenLoaded('facilities', fn () => $this->facilities->pluck('name')),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => ['id' => $i->id, 'url' => $this->imageUrl($i->storage_path), 'thumbnail' => $this->imageUrl($i->thumbnail_path ?: $i->storage_path), 'caption' => $i->caption, 'alt' => $i->alt_text, 'cover' => $i->is_cover, 'quality' => $request->user() && in_array($request->user()->role, ['owner', 'admin'], true) ? ['score' => $i->quality_score, 'flags' => $i->quality_flags ?? []] : null])),
            'image' => $this->whenLoaded('images', fn () => $this->imageUrl(optional($this->images->firstWhere('is_cover', true) ?? $this->images->first())->storage_path)),
            'distanceKm' => $this->when($this->resource->getAttribute('distance_km') !== null, fn () => (float) $this->resource->getAttribute('distance_km')),
            'destinationName' => $this->when($this->resource->getAttribute('destination_name') !== null, fn () => $this->resource->getAttribute('destination_name')),
            'commuteEstimateMinutes' => $this->when($this->resource->getAttribute('commute_estimate_minutes') !== null, fn () => (int) $this->resource->getAttribute('commute_estimate_minutes')),
            'commuteOptions' => $this->when($this->resource->getAttribute('commute_options') !== null, fn () => $this->resource->getAttribute('commute_options')),
            'routeMethod' => $this->when($this->resource->getAttribute('route_method') !== null, fn () => $this->resource->getAttribute('route_method')),
            'recommendedCommuteMode' => $this->when($this->resource->getAttribute('recommended_commute_mode') !== null, fn () => $this->resource->getAttribute('recommended_commute_mode')),
            'routeGeometry' => $this->when($this->resource->getAttribute('route_geometry') !== null, fn () => $this->resource->getAttribute('route_geometry')),
            'nearbyPlaces' => $this->whenLoaded('nearbyPlaces', fn () => $this->nearbyPlaces->sortBy('distance_m')->values()->map(fn ($place) => ['type' => $place->type, 'name' => $place->name, 'distanceM' => $place->distance_m, 'latitude' => $place->latitude ? (float) $place->latitude : null, 'longitude' => $place->longitude ? (float) $place->longitude : null, 'sourceProvider' => $place->source_provider, 'coordinateConfidence' => $place->coordinate_confidence ? (float) $place->coordinate_confidence : null, 'verifiedAt' => $place->verified_at?->toIso8601String()])),
            'matchRank' => $this->when($this->resource->getAttribute('match_rank') !== null, fn () => (int) $this->resource->getAttribute('match_rank')),
            'matchScore' => $this->when($this->resource->getAttribute('match_score') !== null, fn () => (int) $this->resource->getAttribute('match_score')),
            'matchLabel' => $this->when($this->resource->getAttribute('match_label') !== null, fn () => $this->resource->getAttribute('match_label')),
            'matchedRequirements' => $this->when($this->resource->getAttribute('matched_requirements') !== null, fn () => $this->resource->getAttribute('matched_requirements')),
            'matchReasons' => $this->when($this->resource->getAttribute('match_reasons') !== null, fn () => $this->resource->getAttribute('match_reasons')),
            'matchBreakdown' => $this->when($this->resource->getAttribute('match_breakdown') !== null, fn () => $this->resource->getAttribute('match_breakdown')),
        ];
    }
}
