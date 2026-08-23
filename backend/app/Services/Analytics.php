<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Analytics
{
    public static function record(string $event, ?int $listingId = null, array $metadata = []): void
    {
        try {
            DB::table('analytics_events')->insert(['user_id' => request()->user()?->id, 'event_type' => $event, 'listing_id' => $listingId, 'metadata' => $metadata ? json_encode($metadata) : null, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable) {
            // Analytics must never break the user's primary transaction.
        }
    }
}
