<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifyMatchingSavedSearches implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $listingId) {}

    public function handle(): void
    {
        $listing = Listing::find($this->listingId);
        if (! $listing || $listing->status !== 'published') {
            return;
        }
        SavedSearch::where('notifications_enabled', true)->chunkById(100, function ($searches) use ($listing) {
            foreach ($searches as $search) {
                $filters = $search->filters ?? [];
                $matches = (! ($filters['city'] ?? null) || str_contains(mb_strtolower($listing->city.' '.$listing->public_area), mb_strtolower($filters['city'])))
                    && (! ($filters['maxPrice'] ?? null) || $listing->monthly_price_lkr <= $filters['maxPrice'])
                    && (! ($filters['type'] ?? null) || $listing->property_type === $filters['type']);
                if (! $matches) {
                    continue;
                }
                $user = User::find($search->user_id);
                $duplicate = $user?->notifications()->where('data', 'like', '%"link":"/listing/'.$listing->id.'"%')->exists();
                if ($user && ! $duplicate) {
                    $user->notify(new PlatformNotification('saved_search_match', 'New place matching '.$search->name, $listing->title.' is now published in '.$listing->public_area.'.', '/listing/'.$listing->id));
                }
            }
        });
    }
}
