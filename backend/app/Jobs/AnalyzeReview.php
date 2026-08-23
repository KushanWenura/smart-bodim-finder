<?php

namespace App\Jobs;

use App\Models\Review;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AnalyzeReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $reviewId) {}

    public function handle(): void
    {
        $review = Review::find($this->reviewId);
        if (! $review) {
            return;
        }try {
            $result = Http::baseUrl(config('services.smart_bodim_ai.url'))->timeout(3)->withHeaders(['X-Internal-Secret' => config('services.smart_bodim_ai.secret')])->post('/v1/reviews/analyze', ['text' => $review->body])->throw()->json();
            DB::table('review_ai_analyses')->updateOrInsert(['review_id' => $review->id], ['label' => $result['label'] ?? null, 'confidence' => $result['confidence'] ?? null, 'aspects' => json_encode($result['aspects'] ?? []), 'model_version' => $result['modelVersion'] ?? 'unknown', 'status' => 'complete', 'analyzed_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('review_ai_analyses')->updateOrInsert(['review_id' => $review->id], ['status' => 'error', 'error_message' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now(), 'created_at' => now()]);
            throw $e;
        }
    }
}
