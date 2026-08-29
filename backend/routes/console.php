<?php

use App\Jobs\AnalyzeReview;
use App\Jobs\SynchronizeListingIndex;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\Review;
use App\Models\User;
use App\Models\ViewingRequest;
use App\Notifications\PlatformNotification;
use App\Services\AiServiceClient;
use App\Services\ListingImageQualityService;
use App\Services\ReservationAvailabilityService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:seed-demo-if-empty', function () {
    if (User::exists()) {
        $this->info('Existing application data detected; demo seeding skipped.');

        return 0;
    }
    $this->call('db:seed', ['--force' => true]);
    $this->info('Local demonstration data created.');

    return 0;
})->purpose('Seed synthetic local data only when the users table is empty');

Artisan::command('ai:index-rebuild {--sync : Execute immediately instead of queueing}', function () {
    $listings = Listing::publiclyVisible()->with('facilities')->get();

    if (! $this->option('sync')) {
        $listings->each(fn (Listing $listing) => SynchronizeListingIndex::dispatch($listing->id));
        $this->info("Queued {$listings->count()} published listings for index synchronization.");

        return 0;
    }

    $payload = $listings->map(fn (Listing $listing) => [
        'id' => $listing->id,
        'title' => $listing->title,
        'description' => $listing->description,
        'propertyType' => $listing->property_type,
        'area' => $listing->public_area,
        'city' => $listing->city,
        'facilities' => $listing->facilities->pluck('name')->values()->all(),
    ])->values()->all();
    $result = app(AiServiceClient::class)->indexRebuild($payload);

    if (($result['status'] ?? null) !== 'indexed') {
        $this->error('The AI model is not ready, so the index was not replaced.');

        return 1;
    }

    $modelId = DB::table('ai_model_versions')->where('purpose', 'search')->where('active', true)->value('id');
    if ($modelId) {
        $listingIds = $listings->pluck('id')->all();
        DB::transaction(function () use ($listings, $listingIds, $modelId) {
            DB::table('ai_index_records')->where('model_version_id', $modelId)
                ->when($listingIds, fn ($query) => $query->whereNotIn('listing_id', $listingIds))
                ->update(['status' => 'removed', 'updated_at' => now()]);

            foreach ($listings as $listing) {
                $text = implode(' ', [$listing->title, $listing->description, $listing->property_type, $listing->public_area, $listing->city, $listing->facilities->pluck('name')->implode(' ')]);
                DB::table('ai_index_records')->updateOrInsert(
                    ['listing_id' => $listing->id, 'model_version_id' => $modelId],
                    ['vector_key' => "listing:{$listing->id}:model:{$modelId}", 'content_checksum' => hash('sha256', $text), 'status' => 'indexed', 'indexed_at' => now(), 'error_message' => null, 'updated_at' => now(), 'created_at' => now()]
                );
                $listing->update(['last_indexed_at' => now()]);
            }
        });
    }

    $this->info("Rebuilt the AI index with {$listings->count()} published listings.");

    return 0;
})->purpose('Rebuild or queue the active semantic listing index');

Artisan::command('ai:review-rebuild', function () {
    $count = 0;
    Review::select('id')->chunkById(100, function ($reviews) use (&$count) {
        foreach ($reviews as $review) {
            AnalyzeReview::dispatch($review->id);
            $count++;
        }
    });
    $this->info("Queued {$count} reviews for sentiment/aspect analysis.");
})->purpose('Queue fresh AI analysis for all reviews');

Artisan::command('listings:analyze-images {--force : Re-analyze images that already have a score}', function () {
    $quality = app(ListingImageQualityService::class);
    $query = ListingImage::query();
    if (! $this->option('force')) {
        $query->whereNull('analyzed_at');
    }
    $analyzed = 0;
    $missing = 0;
    $query->chunkById(100, function ($images) use ($quality, &$analyzed, &$missing) {
        foreach ($images as $image) {
            $result = $quality->analyzeFile($image->storage_path);
            if (! $result) {
                $missing++;

                continue;
            }
            $image->update([
                'quality_score' => $result['score'],
                'quality_flags' => $result['flags'],
                'perceptual_hash' => $result['perceptualHash'],
                'analyzed_at' => now(),
            ]);
            $analyzed++;
        }
    });
    $this->info("Analyzed {$analyzed} image(s); {$missing} source file(s) were unavailable.");

    return 0;
})->purpose('Backfill local image quality scores and perceptual hashes');

Artisan::command('rentals:send-reminders', function () {
    $count = 0;
    ViewingRequest::with(['tenant', 'owner', 'listing'])->where('status', 'accepted')
        ->whereNull('reminder_sent_at')->whereBetween('proposed_at', [now(), now()->addDay()])
        ->chunkById(100, function ($viewings) use (&$count) {
            foreach ($viewings as $viewing) {
                $message = 'Your viewing for '.$viewing->listing->title.' is scheduled for '.$viewing->proposed_at->format('d M Y, g:i A').'.';
                $viewing->tenant->notify(new PlatformNotification('viewing_reminder', 'Viewing reminder', $message, '/tenant/journey'));
                $viewing->owner->notify(new PlatformNotification('viewing_reminder', 'Viewing reminder', $message, '/owner/journey'));
                $viewing->update(['reminder_sent_at' => now()]);
                $count++;
            }
        });
    $this->info("Queued reminders for {$count} viewing(s).");
})->purpose('Queue tenant and owner reminders for viewings within 24 hours');

Artisan::command('rentals:expire-holds', function () {
    app(ReservationAvailabilityService::class)->expireStaleHolds();
    $this->info('Expired reservation holds were released.');
})->purpose('Release expired 48-hour reservation holds');

Schedule::command('rentals:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('rentals:expire-holds')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10)))
    ->name('system-heartbeat')->everyMinute()->withoutOverlapping();
