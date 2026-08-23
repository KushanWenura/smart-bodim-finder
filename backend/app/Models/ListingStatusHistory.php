<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingStatusHistory extends Model
{
    protected $table = 'listing_status_history';

    protected $guarded = [];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
