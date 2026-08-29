<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalAgreement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'terms_snapshot' => 'array', 'tenant_accepted_at' => 'datetime',
            'owner_accepted_at' => 'datetime', 'generated_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
