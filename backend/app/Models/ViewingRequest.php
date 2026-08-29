<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViewingRequest extends Model
{
    protected $guarded = [];

    protected $hidden = ['emergency_contact_name', 'emergency_contact_phone', 'share_token_hash'];

    protected function casts(): array
    {
        return [
            'proposed_at' => 'datetime', 'alternative_at' => 'datetime', 'responded_at' => 'datetime',
            'completed_at' => 'datetime', 'reminder_sent_at' => 'datetime', 'tenant_checked_in_at' => 'datetime',
            'owner_checked_in_at' => 'datetime', 'tenant_checked_out_at' => 'datetime', 'owner_checked_out_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
