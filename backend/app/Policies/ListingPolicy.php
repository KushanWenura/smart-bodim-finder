<?php

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;

class ListingPolicy
{
    public function update(User $user, Listing $listing): bool
    {
        return $user->role === 'owner' && $listing->owner_id === $user->id && in_array($listing->status, ['draft', 'rejected', 'published'], true);
    }

    public function submit(User $user, Listing $listing): bool
    {
        return $user->role === 'owner' && $listing->owner_id === $user->id && in_array($listing->status, ['draft', 'rejected'], true);
    }

    public function moderate(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
