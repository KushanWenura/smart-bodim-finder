<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingAiRiskAssessment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['flags' => 'array', 'evidence' => 'array', 'assessed_at' => 'datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
