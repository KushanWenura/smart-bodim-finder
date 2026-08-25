<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingNearbyPlace extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['coordinate_confidence' => 'float', 'verified_at' => 'datetime'];
    }
}
