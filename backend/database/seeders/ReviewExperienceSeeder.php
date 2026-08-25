<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\Review;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ReviewExperienceSeeder extends Seeder
{
    public function run(): void
    {
        $listing = Listing::query()->where('public_slug', 'SBF-0001')->first();

        if (! $listing) {
            $this->command?->warn('Review examples skipped because SBF-0001 does not exist.');

            return;
        }

        DB::transaction(function () use ($listing): void {
            $reviewers = [
                [
                    'email' => 'nethmi.reviewer@smartbodim.lk',
                    'name' => 'Nethmi Silva',
                    'phone' => '0752345678',
                    'rating' => 5,
                    'body' => 'The room was bright and quiet enough for late study sessions. WiFi stayed reliable, the air conditioning worked well, and the owner responded quickly whenever I asked for help.',
                ],
                [
                    'email' => 'dilan.reviewer@smartbodim.lk',
                    'name' => 'Dilan Perera',
                    'phone' => '0782345678',
                    'rating' => 4,
                    'body' => 'Very convenient for Moratuwa and the bus route. The covered parking was useful during rain. The room was clean, although traffic can be heard for a short time in the evening.',
                ],
            ];

            foreach ($reviewers as $fixture) {
                $reviewer = User::query()->updateOrCreate(
                    ['email' => $fixture['email']],
                    [
                        'role' => 'tenant',
                        'name' => $fixture['name'],
                        'phone' => $fixture['phone'],
                        'status' => 'active',
                        'password' => Hash::make('Tenant@123'),
                    ]
                );
                $reviewer->forceFill(['email_verified_at' => $reviewer->email_verified_at ?? now()])->save();

                TenantProfile::query()->updateOrCreate(
                    ['user_id' => $reviewer->id],
                    ['category' => 'student', 'institution_or_workplace' => 'University of Moratuwa']
                );

                Review::withTrashed()->updateOrCreate(
                    ['tenant_id' => $reviewer->id, 'listing_id' => $listing->id],
                    [
                        'rating' => $fixture['rating'],
                        'body' => $fixture['body'],
                        'moderation_status' => 'visible',
                        'deleted_at' => null,
                    ]
                );
            }

            $visible = Review::query()->where('listing_id', $listing->id)->where('moderation_status', 'visible');
            $listing->forceFill([
                'average_rating' => round((float) $visible->avg('rating'), 2),
                'review_count' => $visible->count(),
            ])->save();
        });

        $this->command?->info('Seeded a transparent three-review AI-summary example for SBF-0001.');
    }
}
