<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingAvailabilityBlock;
use App\Models\Reservation;
use Carbon\CarbonInterface;

class ReservationAvailabilityService
{
    public function expireStaleHolds(?int $listingId = null): void
    {
        $query = Reservation::where('status', 'held')->where('hold_expires_at', '<=', now());
        if ($listingId) {
            $query->where('listing_id', $listingId);
        }

        $ids = $query->pluck('listing_id')->unique();
        $query->update(['status' => 'expired', 'updated_at' => now()]);
        $ids->each(function (int $id): void {
            $listing = Listing::find($id);
            if ($listing) {
                $this->syncListing($listing);
            }
        });
    }

    public function hasConflict(int $listingId, CarbonInterface|string $from, CarbonInterface|string $to, ?int $exceptId = null): bool
    {
        $this->expireStaleHolds($listingId);

        $reservationConflict = Reservation::where('listing_id', $listingId)
            ->where(function ($query) {
                $query->where('status', 'confirmed')
                    ->orWhere(fn ($held) => $held->where('status', 'held')->where('hold_expires_at', '>', now()));
            })
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->whereDate('move_in_date', '<=', $to)
            ->whereDate('move_out_date', '>=', $from)
            ->exists();

        return $reservationConflict || ListingAvailabilityBlock::where('listing_id', $listingId)
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->exists();
    }

    public function syncListing(Listing $listing): void
    {
        $active = Reservation::where('listing_id', $listing->id)
            ->where(function ($query) {
                $query->where(fn ($confirmed) => $confirmed->where('status', 'confirmed')->whereDate('move_out_date', '>=', today()))
                    ->orWhere(fn ($held) => $held->where('status', 'held')->where('hold_expires_at', '>', now()));
            })
            ->orderByDesc('status')
            ->orderByDesc('move_out_date')
            ->first();

        $blockedToday = ListingAvailabilityBlock::where('listing_id', $listing->id)
            ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())
            ->orderByDesc('end_date')->first();

        $listing->forceFill([
            'available' => ! $active && ! $blockedToday,
            'available_from' => $active
                ? $active->move_out_date->copy()->addDay()
                : ($blockedToday ? $blockedToday->end_date->copy()->addDay() : today()),
        ])->saveQuietly();
    }

    public function snapshot(Listing $listing): array
    {
        $this->expireStaleHolds($listing->id);
        $active = Reservation::where('listing_id', $listing->id)
            ->where(function ($query) {
                $query->where(fn ($confirmed) => $confirmed->where('status', 'confirmed')->whereDate('move_out_date', '>=', today()))
                    ->orWhere(fn ($held) => $held->where('status', 'held')->where('hold_expires_at', '>', now()));
            })->orderByDesc('status')->first();

        $block = ListingAvailabilityBlock::where('listing_id', $listing->id)
            ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->first();
        if ($block) {
            return ['status' => 'unavailable', 'label' => 'Temporarily unavailable', 'nextAvailableFrom' => $block->end_date->copy()->addDay()->toDateString()];
        }

        if (! $active) {
            return ['status' => 'available', 'label' => 'Available for enquiries and viewings', 'nextAvailableFrom' => $listing->available_from?->toDateString()];
        }

        if ($active->status === 'held') {
            return ['status' => 'held', 'label' => 'A reservation is temporarily pending', 'nextAvailableFrom' => $active->move_out_date->copy()->addDay()->toDateString(), 'holdExpiresAt' => $active->hold_expires_at?->toIso8601String()];
        }

        $occupied = $active->move_in_date->lte(today());

        return ['status' => $occupied ? 'occupied' : 'reserved', 'label' => ($occupied ? 'Occupied' : 'Reserved').' until '.$active->move_out_date->format('d M Y'), 'nextAvailableFrom' => $active->move_out_date->copy()->addDay()->toDateString()];
    }
}
