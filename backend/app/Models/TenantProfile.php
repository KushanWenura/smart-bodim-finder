<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantProfile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required_facilities' => 'array',
            'preferred_facilities' => 'array',
            'learned_preferences' => 'array',
            'ai_learning_enabled' => 'boolean',
        ];
    }
}
