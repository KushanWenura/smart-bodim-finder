<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Facility;
use App\Models\Institution;
use App\Models\Listing;
use App\Models\ListingNearbyPlace;
use App\Models\Location;
use App\Models\OwnerProfile;
use App\Models\Review;
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
        $this->seedProximityData();

        if (User::where('email', 'admin@smartbodim.lk')->exists()) {
            $this->command?->info('Smart Bodim sample data already exists; seeding skipped safely.');

            return;
        }

        $admin = User::create(['role' => 'admin', 'name' => 'Platform Administrator', 'email' => 'admin@smartbodim.lk', 'phone' => '0771234567', 'status' => 'active', 'email_verified_at' => now(), 'password' => Hash::make('Admin@123')]);
        $owner = User::create(['role' => 'owner', 'name' => 'Serendib Rooms', 'email' => 'owner@smartbodim.lk', 'phone' => '0712345678', 'status' => 'active', 'email_verified_at' => now(), 'password' => Hash::make('Owner@123')]);
        $owner2 = User::create(['role' => 'owner', 'name' => 'Hill Country Homes', 'email' => 'hillowner@smartbodim.lk', 'phone' => '0723456789', 'status' => 'active', 'email_verified_at' => now(), 'password' => Hash::make('Owner@123')]);
        $tenant = User::create(['role' => 'tenant', 'name' => 'Kavindi Perera', 'email' => 'tenant@smartbodim.lk', 'phone' => '0762345678', 'status' => 'active', 'email_verified_at' => now(), 'password' => Hash::make('Tenant@123')]);
        OwnerProfile::create(['user_id' => $owner->id, 'business_name' => 'Serendib Rooms', 'verification_status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]);
        OwnerProfile::create(['user_id' => $owner2->id, 'business_name' => 'Hill Country Homes', 'verification_status' => 'verified', 'verified_by' => $admin->id, 'verified_at' => now()]);
        TenantProfile::create(['user_id' => $tenant->id, 'category' => 'student', 'institution_or_workplace' => 'University of Moratuwa', 'min_budget_lkr' => 18000, 'max_budget_lkr' => 40000, 'required_facilities' => ['WiFi'], 'preference_text' => 'Quiet furnished room near public transport with WiFi.']);
        $rows = [['Colombo', 'Colombo', 'Colombo 03', 6.9147, 79.8520], ['Colombo', 'Colombo', 'Nugegoda', 6.8649, 79.8997], ['Colombo', 'Colombo', 'Dehiwala', 6.8516, 79.8653], ['Colombo', 'Colombo', 'Moratuwa', 6.7730, 79.8816], ['Kandy', 'Kandy', 'Kandy City', 7.2906, 80.6337], ['Kandy', 'Kandy', 'Peradeniya', 7.2599, 80.5970], ['Colombo', 'Colombo', 'Malabe', 6.9041, 79.9546], ['Colombo', 'Colombo', 'Homagama', 6.8412, 80.0030], ['Galle', 'Galle', 'Galle Fort', 6.0260, 80.2170], ['Galle', 'Galle', 'Karapitiya', 6.0662, 80.2272], ['Kurunegala', 'Kurunegala', 'Kurunegala Town', 7.4863, 80.3623], ['Jaffna', 'Jaffna', 'Jaffna Town', 9.6615, 80.0255]];
        $locations = collect($rows)->map(fn ($x) => Location::create(['district' => $x[0], 'city' => $x[1], 'area' => $x[2], 'latitude' => $x[3], 'longitude' => $x[4]]));
        $names = ['WiFi', 'Fan', 'Air conditioning', 'Meals', 'Parking', 'Attached bathroom', 'Hot water', 'Laundry', 'Kitchen access', 'Study area', 'Security/CCTV', 'Electricity/water included'];
        $facilities = collect($names)->mapWithKeys(fn ($name) => [$name => Facility::create(['code' => str($name)->slug('_'), 'name' => $name, 'category' => 'amenity'])]);
        $titles = ['Sunlit private room', 'Quiet student annex', 'Modern shared residence', 'Garden studio', 'City-view boarding room', 'Calm work-friendly room'];
        $types = ['private_room', 'annex', 'shared_room', 'studio', 'boarding_room', 'hostel'];
        $sets = [['WiFi', 'Fan', 'Attached bathroom', 'Study area'], ['WiFi', 'Kitchen access', 'Hot water', 'Security/CCTV'], ['Meals', 'Laundry', 'Parking', 'Fan'], ['WiFi', 'Air conditioning', 'Attached bathroom', 'Electricity/water included']];
        $photos = ['photo-1522708323590-d24dbb6b0267d', 'photo-1560448204-e02f11c3d0e2', 'photo-1502672260266-1c1ef2d93688', 'photo-1493809842364-78817add7ffb', 'photo-1484154218962-a197022b5858', 'photo-1560185007-c5ca9d2c014d'];
        $listings = collect();
        for ($i = 0; $i < 24; $i++) {
            $loc = $locations[$i % $locations->count()];
            $listing = Listing::create(['owner_id' => $i % 3 === 0 ? $owner2->id : $owner->id, 'location_id' => $loc->id, 'public_slug' => 'SBF-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'title' => $titles[$i % 6].' in '.$loc->area, 'description' => 'A clean, thoughtfully maintained '.str_replace('_', ' ', $types[$i % 6]).' with convenient access to transport, shops and study or work locations. Respectful residents and a calm environment are preferred.', 'property_type' => $types[$i % 6], 'monthly_price_lkr' => 18000 + ($i % 8) * 3500, 'deposit_lkr' => 10000 + ($i % 4) * 5000, 'public_area' => $loc->area, 'city' => $loc->city, 'district' => $loc->district, 'latitude' => (float) $loc->latitude + ($i % 3) * .002, 'longitude' => (float) $loc->longitude + ($i % 4) * .002, 'gender_rule' => ['any', 'female_only', 'male_only'][$i % 3], 'occupancy_limit' => 1 + ($i % 3), 'sharing_allowed' => $i % 2 === 0, 'available' => $i % 7 !== 0, 'available_from' => '2026-09-01', 'furnished' => $i % 3 !== 0, 'house_rules' => 'No smoking. Keep shared spaces tidy and respect quiet hours after 10 PM.', 'status' => 'published', 'average_rating' => 3.8 + ($i % 5) * .2, 'review_count' => 3 + ($i % 12), 'favorite_count' => 5 + ($i * 7) % 41, 'view_count' => 80 + $i * 19, 'published_at' => now()->subDays($i * 3)]);
            $listing->facilities()->sync(collect($sets[$i % 4])->map(fn ($n) => $facilities[$n]->id));
            $listing->images()->create(['storage_path' => 'https://images.unsplash.com/'.$photos[$i % 6].'?auto=format&fit=crop&w=1200&q=80', 'thumbnail_path' => null, 'mime_type' => 'image/jpeg', 'byte_size' => 0, 'width' => 1200, 'height' => 800, 'alt_text' => 'Interior of '.$listing->title, 'sort_order' => 0, 'is_cover' => true]);
            $listings->push($listing);
        }
        $texts = ['Very clean room and the owner responds quickly. WiFi was reliable for online lectures.', 'Safe place with easy bus access. Some traffic noise in the evening.', 'Good value and friendly owner. Shared kitchen could be cleaner.', 'Peaceful location, clean bathroom and excellent security.', 'Convenient location but the room gets noisy at night.'];
        foreach ($texts as $i => $body) {
            Review::create(['tenant_id' => $tenant->id, 'listing_id' => $listings[$i]->id, 'rating' => [5, 4, 4, 5, 3][$i], 'body' => $body, 'moderation_status' => $i === 4 ? 'flagged' : 'visible']);
        }
        DB::table('favorites')->insert([['user_id' => $tenant->id, 'listing_id' => $listings[1]->id, 'created_at' => now(), 'updated_at' => now()], ['user_id' => $tenant->id, 'listing_id' => $listings[4]->id, 'created_at' => now(), 'updated_at' => now()], ['user_id' => $tenant->id, 'listing_id' => $listings[7]->id, 'created_at' => now(), 'updated_at' => now()]]);
        $c = Conversation::create(['listing_id' => $listings[1]->id, 'tenant_id' => $tenant->id, 'owner_id' => $owner->id, 'subject' => 'Availability in September']);
        $c->messages()->createMany([['sender_id' => $tenant->id, 'body' => 'Hello, is this room available from the first week of September?'], ['sender_id' => $owner->id, 'body' => 'Yes, it is. You can arrange a viewing this weekend.']]);
        $tenant->notifyNow(new PlatformNotification('match', 'New match in Nugegoda', 'A verified room matching your WiFi and budget preferences was published.', '/search?city=Colombo'));
        $owner->notifyNow(new PlatformNotification('listing', 'Listing approved', 'Your listing is live.', '/owner/listings'));
        DB::table('ai_model_versions')->insert([['purpose' => 'search', 'version' => 'fixture-tfidf-1.0.0', 'base_model' => 'deterministic-tfidf', 'manifest' => json_encode(['profile' => 'tiny-cpu-fixture']), 'active' => true, 'created_at' => now(), 'updated_at' => now()], ['purpose' => 'sentiment', 'version' => 'fixture-lexicon-1.0.0', 'base_model' => 'lexicon-template', 'manifest' => json_encode(['profile' => 'tiny-cpu-fixture']), 'active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        $this->seedProximityData();
    }

    private function seedProximityData(): void
    {
        $icbtSource = 'https://icbt.lk/branches/';
        $destinations = [
            ['name' => 'University of Moratuwa', 'type' => 'campus', 'latitude' => 6.7969, 'longitude' => 79.9018, 'aliases' => ['uom', 'moratuwa university', 'katubedda campus']],
            ['name' => 'University of Colombo', 'type' => 'campus', 'latitude' => 6.9003, 'longitude' => 79.8587, 'aliases' => ['uoc', 'colombo university']],
            ['name' => 'University of Sri Jayewardenepura', 'type' => 'campus', 'latitude' => 6.8528, 'longitude' => 79.9036, 'aliases' => ['usj', 'jayewardenepura university', 'japura university']],
            ['name' => 'SLIIT Malabe Campus', 'type' => 'campus', 'latitude' => 6.9147, 'longitude' => 79.9739, 'organization' => 'SLIIT', 'branch' => 'Malabe', 'aliases' => ['sliit', 'sliit malabe']],
            ['name' => 'NSBM Green University', 'type' => 'campus', 'latitude' => 6.8213, 'longitude' => 80.0407, 'aliases' => ['nsbm', 'nsbm homagama']],
            ['name' => 'University of Kelaniya', 'type' => 'campus', 'latitude' => 6.9749, 'longitude' => 79.9159, 'aliases' => ['uok', 'kelaniya university']],
            ['name' => 'University of Peradeniya', 'type' => 'campus', 'latitude' => 7.2541, 'longitude' => 80.5974, 'aliases' => ['uop', 'peradeniya university']],
            ['name' => 'University of Ruhuna', 'type' => 'campus', 'latitude' => 5.9383, 'longitude' => 80.5763, 'aliases' => ['uor', 'ruhuna university']],
            ['name' => 'University of Jaffna', 'type' => 'campus', 'latitude' => 9.6849, 'longitude' => 80.0220, 'aliases' => ['uofj', 'jaffna university']],
            ['name' => 'Kotelawala Defence University', 'type' => 'campus', 'latitude' => 6.8207, 'longitude' => 79.8868, 'aliases' => ['kdu', 'kotelawala university']],
            ['name' => 'ICBT Campus - Colombo', 'type' => 'campus', 'latitude' => 6.8859, 'longitude' => 79.8573, 'organization' => 'ICBT Campus', 'branch' => 'Colombo', 'aliases' => ['icbt', 'icbt campus', 'icbt colombo', 'icbt bambalapitiya'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Kandy', 'type' => 'campus', 'latitude' => 7.2963, 'longitude' => 80.6350, 'organization' => 'ICBT Campus', 'branch' => 'Kandy', 'aliases' => ['icbt', 'icbt campus', 'icbt kandy'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Galle', 'type' => 'campus', 'latitude' => 6.0375, 'longitude' => 80.2160, 'organization' => 'ICBT Campus', 'branch' => 'Galle', 'aliases' => ['icbt', 'icbt campus', 'icbt galle'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Nugegoda', 'type' => 'campus', 'latitude' => 6.8721, 'longitude' => 79.8899, 'organization' => 'ICBT Campus', 'branch' => 'Nugegoda', 'aliases' => ['icbt', 'icbt campus', 'icbt nugegoda'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Batticaloa', 'type' => 'campus', 'latitude' => 7.7102, 'longitude' => 81.7026, 'organization' => 'ICBT Campus', 'branch' => 'Batticaloa', 'aliases' => ['icbt', 'icbt campus', 'icbt batticaloa'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Matara', 'type' => 'campus', 'latitude' => 5.9485, 'longitude' => 80.5350, 'organization' => 'ICBT Campus', 'branch' => 'Matara', 'aliases' => ['icbt', 'icbt campus', 'icbt matara', 'icbt southern campus'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Jaffna', 'type' => 'campus', 'latitude' => 9.6667, 'longitude' => 80.0250, 'organization' => 'ICBT Campus', 'branch' => 'Jaffna', 'aliases' => ['icbt', 'icbt campus', 'icbt jaffna'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Kurunegala', 'type' => 'campus', 'latitude' => 7.4863, 'longitude' => 80.3652, 'organization' => 'ICBT Campus', 'branch' => 'Kurunegala', 'aliases' => ['icbt', 'icbt campus', 'icbt kurunegala'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Gampaha', 'type' => 'campus', 'latitude' => 7.0912, 'longitude' => 79.9983, 'organization' => 'ICBT Campus', 'branch' => 'Gampaha', 'aliases' => ['icbt', 'icbt campus', 'icbt gampaha'], 'source' => $icbtSource],
            ['name' => 'ICBT Campus - Anuradhapura', 'type' => 'campus', 'latitude' => 8.3350, 'longitude' => 80.4108, 'organization' => 'ICBT Campus', 'branch' => 'Anuradhapura', 'aliases' => ['icbt', 'icbt campus', 'icbt anuradhapura'], 'source' => $icbtSource],
            ['name' => 'World Trade Center Colombo', 'type' => 'workplace', 'latitude' => 6.9320, 'longitude' => 79.8440, 'aliases' => ['wtc', 'world trade centre', 'fort office']],
            ['name' => 'Orion City IT Park', 'type' => 'workplace', 'latitude' => 6.9466, 'longitude' => 79.8795, 'aliases' => ['orion city', 'dematagoda it park']],
            ['name' => 'TRACE Expert City', 'type' => 'workplace', 'latitude' => 6.9270, 'longitude' => 79.8624, 'aliases' => ['trace city', 'maradana tech hub']],
            ['name' => 'Kandy City Centre', 'type' => 'workplace', 'latitude' => 7.2938, 'longitude' => 80.6407, 'aliases' => ['kcc', 'kandy office']],
            ['name' => 'Galle City Centre', 'type' => 'workplace', 'latitude' => 6.0329, 'longitude' => 80.2168, 'aliases' => ['galle office', 'galle town workplace']],
            ['name' => 'Colombo South Teaching Hospital', 'type' => 'workplace', 'latitude' => 6.8667, 'longitude' => 79.8771, 'aliases' => ['kalubowila hospital', 'csth']],
            ['name' => 'National Hospital of Sri Lanka', 'type' => 'workplace', 'latitude' => 6.9187, 'longitude' => 79.8685, 'aliases' => ['national hospital colombo', 'nhsl']],
            ['name' => 'Teaching Hospital Karapitiya', 'type' => 'workplace', 'latitude' => 6.0669, 'longitude' => 80.2261, 'aliases' => ['karapitiya hospital', 'th karapitiya']],
        ];
        foreach ($destinations as $destination) {
            Institution::query()->updateOrCreate(['name' => $destination['name']], [
                'type' => $destination['type'],
                'organization_name' => $destination['organization'] ?? $destination['name'],
                'branch_name' => $destination['branch'] ?? null,
                'aliases' => $destination['aliases'] ?? [],
                'source_url' => $destination['source'] ?? null,
                'latitude' => $destination['latitude'],
                'longitude' => $destination['longitude'],
                'active' => true,
            ]);
        }

        if (! DB::getSchemaBuilder()->hasTable('listings') || ! Listing::query()->exists()) {
            return;
        }

        $places = [
            ['bus_station', 'Colombo Fort Central Bus Stand', 6.9340, 79.8500], ['train_station', 'Colombo Fort Railway Station', 6.9345, 79.8500], ['supermarket', 'Cargills Food City Colombo 03', 6.9109, 79.8520], ['hospital', 'National Hospital of Sri Lanka', 6.9187, 79.8685], ['food', 'Kollupitiya dining area', 6.9115, 79.8526],
            ['bus_station', 'Nugegoda Bus Stand', 6.8721, 79.8899], ['train_station', 'Nugegoda Railway Station', 6.8731, 79.8894], ['supermarket', 'Cargills Food City Nugegoda', 6.8714, 79.8901], ['hospital', 'Colombo South Teaching Hospital', 6.8667, 79.8771], ['food', 'Nugegoda town food court', 6.8725, 79.8903],
            ['bus_station', 'Dehiwala Bus Stand', 6.8513, 79.8655], ['train_station', 'Dehiwala Railway Station', 6.8510, 79.8622], ['supermarket', 'Cargills Food City Dehiwala', 6.8527, 79.8656], ['food', 'Dehiwala Junction dining area', 6.8520, 79.8652],
            ['bus_station', 'Moratuwa Bus Stand', 6.7735, 79.8818], ['train_station', 'Moratuwa Railway Station', 6.7733, 79.8820], ['supermarket', 'Cargills Food City Moratuwa', 6.7744, 79.8825], ['hospital', 'Lunawa District Hospital', 6.7899, 79.8796], ['food', 'Moratuwa town dining area', 6.7741, 79.8822],
            ['bus_station', 'Malabe Bus Stand', 6.9043, 79.9548], ['supermarket', 'Cargills Food City Malabe', 6.9051, 79.9542], ['hospital', 'Neville Fernando Teaching Hospital', 6.9235, 79.9602], ['food', 'Malabe Junction dining area', 6.9048, 79.9550],
            ['bus_station', 'Homagama Bus Stand', 6.8442, 80.0032], ['train_station', 'Homagama Railway Station', 6.8454, 80.0035], ['supermarket', 'Cargills Food City Homagama', 6.8429, 80.0030], ['hospital', 'Homagama Base Hospital', 6.8418, 80.0140], ['food', 'Homagama town dining area', 6.8438, 80.0034],
            ['bus_station', 'Kandy Good Shed Bus Stand', 7.2902, 80.6318], ['train_station', 'Kandy Railway Station', 7.2909, 80.6320], ['supermarket', 'Cargills Food City Kandy', 7.2937, 80.6385], ['hospital', 'National Hospital Kandy', 7.2863, 80.6332], ['food', 'Kandy City Centre food court', 7.2938, 80.6407],
            ['bus_station', 'Peradeniya Junction Bus Stop', 7.2634, 80.5935], ['train_station', 'Peradeniya Junction Railway Station', 7.2578, 80.5896], ['supermarket', 'Cargills Food City Peradeniya', 7.2644, 80.5940], ['hospital', 'Teaching Hospital Peradeniya', 7.2620, 80.5978], ['food', 'Peradeniya Junction dining area', 7.2637, 80.5939],
            ['bus_station', 'Galle Central Bus Station', 6.0351, 80.2149], ['train_station', 'Galle Railway Station', 6.0338, 80.2145], ['supermarket', 'Cargills Food City Galle', 6.0327, 80.2172], ['hospital', 'Teaching Hospital Karapitiya', 6.0669, 80.2261], ['food', 'Galle Fort dining precinct', 6.0265, 80.2168],
            ['bus_station', 'Kurunegala Central Bus Stand', 7.4867, 80.3631], ['train_station', 'Kurunegala Railway Station', 7.4807, 80.3558], ['supermarket', 'Cargills Food City Kurunegala', 7.4870, 80.3638], ['hospital', 'Teaching Hospital Kurunegala', 7.4848, 80.3576], ['food', 'Kurunegala town dining area', 7.4869, 80.3634],
            ['bus_station', 'Jaffna Central Bus Stand', 9.6650, 80.0107], ['train_station', 'Jaffna Railway Station', 9.6689, 80.0142], ['supermarket', 'Cargills Food City Jaffna', 9.6658, 80.0115], ['hospital', 'Teaching Hospital Jaffna', 9.6710, 80.0166], ['food', 'Jaffna town dining area', 9.6655, 80.0120],
        ];
        $grouped = collect($places)->groupBy(fn ($place) => $place[0]);
        Listing::query()->each(function (Listing $listing) use ($grouped): void {
            foreach ($grouped as $type => $candidates) {
                $nearest = $candidates->sortBy(fn ($place) => $this->distanceKm((float) $listing->latitude, (float) $listing->longitude, $place[2], $place[3]))->first();
                $distanceM = (int) round($this->distanceKm((float) $listing->latitude, (float) $listing->longitude, $nearest[2], $nearest[3]) * 1000);
                ListingNearbyPlace::query()->updateOrCreate(['listing_id' => $listing->id, 'type' => $type], ['name' => $nearest[1], 'distance_m' => $distanceM, 'latitude' => $nearest[2], 'longitude' => $nearest[3]]);
            }
        });
        if (DB::getSchemaBuilder()->hasTable('ai_model_versions')) {
            DB::table('ai_model_versions')->where('purpose', 'search')->update(['active' => false, 'updated_at' => now()]);
            DB::table('ai_model_versions')->updateOrInsert(
                ['purpose' => 'search', 'version' => 'smart-bodim-minilm-v1'],
                ['base_model' => 'sentence-transformers/all-MiniLM-L6-v2', 'manifest' => json_encode(['profile' => 'fine-tuned', 'dataset' => 'smart-bodim-synthetic-domain-v1', 'trainPairs' => 34, 'heldOutPairs' => 14, 'loss' => 'MultipleNegativesRankingLoss']), 'active' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return 6371.0088 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
