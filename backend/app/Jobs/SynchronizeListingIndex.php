<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Services\AiServiceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SynchronizeListingIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $listingId, public bool $remove = false) {}

    public function handle(AiServiceClient $ai): void
    {
        $listing = Listing::with('facilities')->find($this->listingId);
        if (! $listing) {
            return;
        }
        $modelId = DB::table('ai_model_versions')->where('purpose', 'search')->where('active', true)->value('id');
        if (! $modelId) {
            return;
        }
        $text = implode(' ', [$listing->title, $listing->description, $listing->property_type, $listing->public_area, $listing->city, $listing->facilities->pluck('name')->implode(' ')]);
        $checksum = hash('sha256', $text);
        DB::table('ai_index_records')->updateOrInsert(['listing_id' => $listing->id, 'model_version_id' => $modelId], ['vector_key' => "listing:{$listing->id}:model:{$modelId}", 'content_checksum' => $checksum, 'status' => 'pending', 'error_message' => null, 'updated_at' => now(), 'created_at' => now()]);
        try {
            $result = $this->remove ? $ai->indexDelete($listing->id) : $ai->indexUpsert($listing->id, $text);
            if (($result['status'] ?? null) === 'pending') {
                DB::table('ai_index_records')->where(['listing_id' => $listing->id, 'model_version_id' => $modelId])->update(['status' => 'pending', 'error_message' => $result['reason'] ?? 'AI model/index is not ready.', 'updated_at' => now()]);

                return;
            }
            DB::table('ai_index_records')->where(['listing_id' => $listing->id, 'model_version_id' => $modelId])->update(['status' => $this->remove ? 'removed' : 'indexed', 'indexed_at' => now(), 'error_message' => null, 'updated_at' => now()]);
            if (! $this->remove) {
                $listing->update(['last_indexed_at' => now()]);
            }
        } catch (\Throwable $exception) {
            DB::table('ai_index_records')->where(['listing_id' => $listing->id, 'model_version_id' => $modelId])->update(['status' => 'error', 'error_message' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
            // Indexing is an enhancement, not a publication dependency. Keep
            // the listing workflow available and leave a visible error record
            // for a later queue retry or administrator rebuild.
            report($exception);
        }
    }
}
