<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
