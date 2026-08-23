<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'active' => 'boolean'];
    }
}
