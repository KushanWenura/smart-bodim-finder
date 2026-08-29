<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['available' => 'boolean', 'sharing_allowed' => 'boolean', 'furnished' => 'boolean', 'available_from' => 'date', 'submitted_at' => 'datetime', 'published_at' => 'datetime', 'last_indexed_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'average_rating' => 'decimal:2'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'listing_facility');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ListingImage::class)->orderBy('sort_order');
    }

    public function nearbyPlaces(): HasMany
    {
        return $this->hasMany(ListingNearbyPlace::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ListingStatusHistory::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function areaSafetyReports(): HasMany
    {
        return $this->hasMany(AreaSafetyReport::class);
    }

    public function viewingRequests(): HasMany
    {
        return $this->hasMany(ViewingRequest::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function rentalSettings(): HasOne
    {
        return $this->hasOne(ListingRentalSetting::class);
    }

    public function availabilityBlocks(): HasMany
    {
        return $this->hasMany(ListingAvailabilityBlock::class);
    }

    public function scopePubliclyVisible($query)
    {
        return $query->where('status', 'published')->where('available', true);
    }
}
