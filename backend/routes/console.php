<?php

use App\Jobs\AnalyzeReview;
use App\Jobs\SynchronizeListingIndex;
use App\Models\Listing;
use App\Models\Review;
use App\Models\User;
use App\Services\AiServiceClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
    $count = 0;
    Listing::publiclyVisible()->select('id')->chunkById(100, function ($listings) use (&$count) {
        foreach ($listings as $listing) {
            $this->option('sync')
                ? (new SynchronizeListingIndex($listing->id))->handle(app(AiServiceClient::class))
                : SynchronizeListingIndex::dispatch($listing->id);
            $count++;
        }
    });
    $this->info("Prepared {$count} published listings for index synchronization.");
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
