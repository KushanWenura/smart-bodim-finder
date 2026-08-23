<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function participate(User $user, Conversation $conversation): bool
    {
        return in_array($user->id, [$conversation->tenant_id, $conversation->owner_id], true);
    }
}
