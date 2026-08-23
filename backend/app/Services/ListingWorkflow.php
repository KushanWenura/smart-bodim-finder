<?php

namespace App\Services;

use App\Jobs\NotifyMatchingSavedSearches;
use App\Jobs\SynchronizeListingIndex;
use App\Models\AdminAuditLog;
use App\Models\Listing;
use App\Models\ListingStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ListingWorkflow
{
    private const TRANSITIONS = ['draft' => ['pending_review'], 'rejected' => ['pending_review'], 'pending_review' => ['published', 'rejected'], 'published' => ['change_pending', 'deactivated', 'suspended'], 'change_pending' => ['published', 'rejected_changes'], 'suspended' => ['published', 'archived']];

    public function transition(Listing $listing, string $to, User $actor, string $reason): Listing
    {
        if (! in_array($to, self::TRANSITIONS[$listing->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Invalid listing transition from {$listing->status} to {$to}."]);
        }

        $updated = DB::transaction(function () use ($listing, $to, $actor, $reason) {
            $before = $listing->status;
            $listing->status = $to;
            $listing->moderation_feedback = in_array($to, ['rejected', 'rejected_changes'], true) ? $reason : null;
            if ($to === 'pending_review') {
                $listing->submitted_at = now();
            } if ($to === 'published') {
                $listing->published_at ??= now();
                $listing->available = true;
            } if ($to === 'deactivated') {
                $listing->deactivated_at = now();
            } $listing->save();
            ListingStatusHistory::create(['listing_id' => $listing->id, 'actor_id' => $actor->id, 'previous_status' => $before, 'new_status' => $to, 'reason' => $reason]);
            if ($actor->role === 'admin') {
                AdminAuditLog::create(['actor_id' => $actor->id, 'action' => "listing.$to", 'target_type' => 'listing', 'target_id' => $listing->id, 'reason' => $reason, 'before_state' => ['status' => $before], 'after_state' => ['status' => $to], 'request_id' => request()->header('X-Request-ID'), 'ip_hash' => hash('sha256', (string) request()->ip())]);
            }

            return $listing->fresh(['owner', 'facilities', 'images']);
        });

        if ($to === 'published') {
            SynchronizeListingIndex::dispatch($updated->id)->afterCommit();
            NotifyMatchingSavedSearches::dispatch($updated->id)->afterCommit();
        } elseif (in_array($to, ['deactivated', 'suspended', 'archived'], true)) {
            SynchronizeListingIndex::dispatch($updated->id, true)->afterCommit();
        }

        Analytics::record('listing_'.$to, $updated->id);

        return $updated;
    }
}
