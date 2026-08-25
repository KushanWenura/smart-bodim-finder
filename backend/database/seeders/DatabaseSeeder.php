<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\OwnerProfile;
use App\Models\TenantProfile;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(InstitutionSeeder::class);

        $admin = $this->account('admin@smartbodim.lk', [
            'role' => 'admin', 'name' => 'Platform Administrator', 'phone' => '0771234567', 'password' => 'Admin@123',
        ]);
        $owner = $this->account('owner@smartbodim.lk', [
            'role' => 'owner', 'name' => 'Serendib Rooms', 'phone' => '0712345678', 'password' => 'Owner@123',
        ]);
        $hillOwner = $this->account('hillowner@smartbodim.lk', [
            'role' => 'owner', 'name' => 'Hill Country Homes', 'phone' => '0723456789', 'password' => 'Owner@123',
        ]);
        $tenant = $this->account('tenant@smartbodim.lk', [
            'role' => 'tenant', 'name' => 'Kavindi Perera', 'phone' => '0762345678', 'password' => 'Tenant@123',
        ]);

        OwnerProfile::query()->updateOrCreate(
            ['user_id' => $owner->id],
            ['business_name' => 'Serendib Rooms', 'verification_status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]
        );
        OwnerProfile::query()->updateOrCreate(
            ['user_id' => $hillOwner->id],
            ['business_name' => 'Hill Country Homes', 'verification_status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]
        );
        TenantProfile::query()->updateOrCreate(
            ['user_id' => $tenant->id],
            [
                'category' => 'student',
                'institution_or_workplace' => 'University of Moratuwa',
                'min_budget_lkr' => 18000,
                'max_budget_lkr' => 40000,
                'required_facilities' => ['WiFi'],
                'preference_text' => 'Quiet furnished room near public transport with WiFi.',
            ]
        );

        foreach ($this->facilityNames() as $name) {
            Facility::query()->updateOrCreate(
                ['code' => str($name)->slug('_')],
                ['name' => $name, 'category' => 'amenity']
            );
        }

        $this->seedModelVersions();
        $this->call(SriLankanListingSeeder::class);
        $this->call(ReviewExperienceSeeder::class);
        $this->seedNotifications($owner, $tenant);
    }

    private function account(string $email, array $data): User
    {
        $password = $data['password'];
        unset($data['password']);

        return User::query()->updateOrCreate(
            ['email' => $email],
            $data + ['status' => 'active', 'email_verified_at' => now(), 'password' => Hash::make($password)]
        );
    }

    private function facilityNames(): array
    {
        return [
            'WiFi', 'Fan', 'Air conditioning', 'Meals', 'Parking', 'Attached bathroom',
            'Hot water', 'Laundry', 'Kitchen access', 'Study area', 'Security/CCTV',
            'Electricity/water included',
        ];
    }

    private function seedModelVersions(): void
    {
        DB::table('ai_model_versions')->where('purpose', 'search')->update(['active' => false, 'updated_at' => now()]);
        DB::table('ai_model_versions')->updateOrInsert(
            ['purpose' => 'search', 'version' => 'smart-bodim-minilm-v1'],
            [
                'base_model' => 'sentence-transformers/all-MiniLM-L6-v2',
                'manifest' => json_encode([
                    'profile' => 'fine-tuned',
                    'dataset' => 'smart-bodim-synthetic-domain-v3',
                    'trainPairs' => 5376,
                    'heldOutPairs' => 2304,
                    'loss' => 'CosineTripletMarginLoss',
                ]),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('ai_model_versions')->updateOrInsert(
            ['purpose' => 'sentiment', 'version' => 'fixture-lexicon-1.0.0'],
            [
                'base_model' => 'lexicon-template',
                'manifest' => json_encode(['profile' => 'tiny-cpu-fixture']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('ai_model_versions')->where('purpose', 'query_intent')->update(['active' => false, 'updated_at' => now()]);
        DB::table('ai_model_versions')->updateOrInsert(
            ['purpose' => 'query_intent', 'version' => 'query-intent-v1'],
            [
                'base_model' => 'character-tfidf-logistic-regression',
                'manifest' => json_encode(['profile' => 'trained-local', 'dataset' => 'query_intent_examples.jsonl', 'rows' => 960, 'languages' => ['en', 'si', 'ta', 'singlish']]),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function seedNotifications(User $owner, User $tenant): void
    {
        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', [$owner->id, $tenant->id])
            ->delete();

        $tenant->notifyNow(new PlatformNotification(
            'match',
            'New exact matches near University of Moratuwa',
            'Three verified rooms match your WiFi, air conditioning, parking and budget requirements.',
            '/search?destination=University%20of%20Moratuwa'
        ));
        $owner->notifyNow(new PlatformNotification(
            'listing',
            'Sri Lankan property catalogue published',
            'Your verified listings are live with original photos and nearby-place information.',
            '/owner/listings'
        ));
    }
}
