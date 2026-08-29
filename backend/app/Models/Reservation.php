<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'move_in_date' => 'date', 'move_out_date' => 'date', 'hold_expires_at' => 'datetime',
            'owner_accepted_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function viewingRequest(): BelongsTo
    {
        return $this->belongsTo(ViewingRequest::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function agreement(): HasOne
    {
        return $this->hasOne(RentalAgreement::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(RentalDispute::class);
    }
}
