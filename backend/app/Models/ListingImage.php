<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingImage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean', 'quality_flags' => 'array', 'analyzed_at' => 'datetime'];
    }
}
