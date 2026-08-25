<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Facility;
use App\Models\Listing;
use App\Models\ListingNearbyPlace;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SriLankanListingSeeder extends Seeder
{
    public function run(): void
    {
        $owners = User::query()->whereIn('email', ['owner@smartbodim.lk', 'hillowner@smartbodim.lk'])->get()->keyBy('email');
        $tenant = User::query()->where('email', 'tenant@smartbodim.lk')->firstOrFail();
        $facilities = Facility::query()->get()->keyBy('name');

        DB::transaction(function () use ($owners, $tenant, $facilities): void {
            // This fixture seeder deliberately replaces the listing catalogue. It is never run by start-all.ps1.
            Conversation::query()->delete();
            Listing::withTrashed()->forceDelete();

            $listings = collect($this->listings())->map(function (array $data, int $index) use ($owners, $facilities): Listing {
                $location = Location::query()->updateOrCreate(
                    ['district' => $data['district'], 'city' => $data['city'], 'area' => $data['area']],
                    ['latitude' => $data['latitude'], 'longitude' => $data['longitude']]
                );
                unset($data['district'], $data['city'], $data['area']);

                $facilityNames = $data['facilities'];
                unset($data['facilities']);
                $ownerEmail = $data['owner_email'];
                unset($data['owner_email']);
                $image = $data['image'];
                unset($data['image']);

                $listing = Listing::query()->create(array_merge($data, [
                    'owner_id' => $owners[$ownerEmail]->id,
                    'location_id' => $location->id,
                    'public_slug' => 'SBF-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'public_area' => $location->area,
                    'city' => $location->city,
                    'district' => $location->district,
                    'available' => true,
                    'available_from' => '2026-09-01',
                    'status' => 'published',
                    'published_at' => now()->subDays(($index + 1) * 2),
                ]));

                $listing->facilities()->sync(collect($facilityNames)->map(fn (string $name) => $facilities[$name]->id));
                $listing->images()->create([
                    'storage_path' => '/images/listings/'.$image,
                    'thumbnail_path' => '/images/listings/'.$image,
                    'mime_type' => 'image/webp',
                    'byte_size' => filesize(public_path('images/listings/'.$image)),
                    'width' => 1200,
                    'height' => 800,
                    'alt_text' => $listing->title.' in '.$listing->public_area.', Sri Lanka',
                    'sort_order' => 0,
                    'is_cover' => true,
                ]);

                return $listing;
            });

            $this->seedNearbyPlaces($listings);
            $this->seedInteractions($listings, $tenant);
        });

        $this->command?->info('Replaced the catalogue with 24 original Sri Lankan listings and photos.');
    }

    private function listings(): array
    {
        $serendib = 'owner@smartbodim.lk';
        $hillCountry = 'hillowner@smartbodim.lk';

        return [
            $this->listing('Teal Study Room near Katubedda', 'A bright owner-verified room in a quiet Moratuwa lane, arranged for serious study with a teak desk, private bathroom, air conditioning and covered parking. University of Moratuwa, Galle Road buses and everyday shopping are within easy reach.', 'private_room', 28500, 20000, 'Moratuwa', 'Moratuwa', 'Colombo', 6.7824, 79.8848, 'any', 1, false, true, ['WiFi', 'Air conditioning', 'Parking', 'Attached bathroom', 'Study area'], 'sbf-0001-moratuwa-study-room.webp', $serendib, 4.9, 18, 42, 386),
            $this->listing('Courtyard Annex in Nugegoda', 'A self-contained annex opening to a leafy internal courtyard, with a compact pantry, hot-water bathroom and secure off-street parking. Ideal for a postgraduate student or young professional who needs privacy near Nugegoda town.', 'annex', 32000, 30000, 'Nugegoda', 'Nugegoda', 'Colombo', 6.8662, 79.8979, 'any', 2, true, true, ['WiFi', 'Kitchen access', 'Hot water', 'Parking', 'Security/CCTV'], 'sbf-0002-nugegoda-courtyard-annex.webp', $serendib, 4.7, 14, 35, 301),
            $this->listing('Coastal Breeze Studio in Dehiwala', 'A calm upper-floor studio with sea-breeze windows, a queen bed, writing corner and private bathroom. The building has CCTV and quick access to Dehiwala railway station, supermarkets and the coastal bus corridor.', 'studio', 34000, 25000, 'Dehiwala', 'Dehiwala-Mount Lavinia', 'Colombo', 6.8518, 79.8658, 'female_only', 1, false, true, ['WiFi', 'Air conditioning', 'Attached bathroom', 'Security/CCTV', 'Hot water'], 'sbf-0003-dehiwala-coastal-studio.webp', $serendib, 4.8, 11, 31, 274),
            $this->listing('Campus Walk Room in Katubedda', 'A practical furnished room designed for University of Moratuwa students, with fast WiFi, air conditioning, a full study desk and a covered car or motorbike space. The Katubedda campus gate and frequent buses are a short walk away.', 'boarding_room', 24500, 15000, 'Katubedda', 'Moratuwa', 'Colombo', 6.7969, 79.9007, 'any', 1, false, true, ['WiFi', 'Air conditioning', 'Parking', 'Study area', 'Electricity/water included'], 'sbf-0004-katubedda-campus-room.webp', $serendib, 4.9, 22, 57, 488),
            $this->listing('Modern Garden Annex in Malabe', 'A modern one-bedroom annex beside a tropical garden, with its own kitchen, air conditioning and gated parking. Suited to SLIIT students or technology-sector professionals seeking a quiet base near Malabe Junction.', 'annex', 38000, 35000, 'Malabe', 'Malabe', 'Colombo', 6.9064, 79.9580, 'any', 2, true, true, ['WiFi', 'Air conditioning', 'Parking', 'Kitchen access', 'Attached bathroom'], 'sbf-0005-malabe-garden-annex.webp', $serendib, 4.8, 16, 39, 352),
            $this->listing('Green Lane Student Room in Homagama', 'A spacious room in a family-run home on a green residential lane, offering air conditioning, dependable WiFi, a proper study area and gated parking. NSBM, Homagama town and the railway station are conveniently reachable.', 'private_room', 22000, 15000, 'Homagama', 'Homagama', 'Colombo', 6.8422, 80.0045, 'any', 1, false, true, ['WiFi', 'Air conditioning', 'Parking', 'Study area', 'Laundry'], 'sbf-0006-homagama-green-lane.webp', $serendib, 4.6, 9, 28, 219),
            $this->listing('Hill View Boarding Room in Kandy', 'A peaceful furnished room with a balcony view across Kandy hills, warm timber furniture and reliable hot water for cool mornings. The railway station, hospital and city-centre bus connections are nearby.', 'boarding_room', 30000, 20000, 'Kandy City', 'Kandy', 'Kandy', 7.2921, 80.6350, 'female_only', 1, false, true, ['WiFi', 'Hot water', 'Study area', 'Attached bathroom', 'Security/CCTV'], 'sbf-0007-kandy-hill-view.webp', $hillCountry, 4.9, 20, 48, 417),
            $this->listing('Riverside Shared Student Home', 'A friendly shared student home near Peradeniya with a long communal table, riverside greenery and generous shared kitchen. Monthly rent includes WiFi, selected meals and laundry access.', 'shared_room', 19000, 10000, 'Peradeniya', 'Kandy', 'Kandy', 7.2611, 80.5964, 'female_only', 3, true, true, ['WiFi', 'Kitchen access', 'Meals', 'Laundry', 'Study area'], 'sbf-0008-peradeniya-shared-home.webp', $hillCountry, 4.7, 27, 61, 531),
            $this->listing('Dutch Quarter Courtyard Room', 'A character-filled room around a shaded courtyard near Galle Fort, combining colonial proportions with a clean modern bathroom and air conditioning. Restaurants, the railway station and the central bus stand are easy to reach.', 'private_room', 33000, 25000, 'Galle Fort', 'Galle', 'Galle', 6.0270, 80.2177, 'any', 2, true, true, ['WiFi', 'Air conditioning', 'Attached bathroom', 'Security/CCTV', 'Hot water'], 'sbf-0009-galle-courtyard-room.webp', $serendib, 4.8, 13, 44, 338),
            $this->listing('Medical Student Annex near Karapitiya', 'A tidy ground-floor annex planned for clinical schedules, with a private pantry, study table and secure parking. Teaching Hospital Karapitiya, grocery shops and direct Galle buses are close by.', 'annex', 27000, 20000, 'Karapitiya', 'Galle', 'Galle', 6.0667, 80.2280, 'any', 2, true, true, ['WiFi', 'Fan', 'Kitchen access', 'Parking', 'Study area'], 'sbf-0010-karapitiya-medical-annex.webp', $serendib, 4.6, 12, 30, 245),
            $this->listing('Mango Shade Garden Studio', 'A private garden studio shaded by a mature mango tree, with an airy sleeping area, air conditioning and room for a car. Kurunegala town services are close while the setting remains quiet after work.', 'studio', 26000, 20000, 'Kurunegala Town', 'Kurunegala', 'Kurunegala', 7.4874, 80.3637, 'any', 2, true, true, ['WiFi', 'Air conditioning', 'Parking', 'Attached bathroom', 'Kitchen access'], 'sbf-0011-kurunegala-mango-studio.webp', $hillCountry, 4.7, 10, 33, 287),
            $this->listing('Jaffna Veranda Boarding Room', 'A high-ceilinged Jaffna room with red-oxide floors, a shaded front veranda and a dedicated study table. It offers excellent ventilation, WiFi and secure parking near the town centre and university routes.', 'boarding_room', 24000, 15000, 'Jaffna Town', 'Jaffna', 'Jaffna', 9.6641, 80.0182, 'male_only', 1, false, true, ['WiFi', 'Fan', 'Parking', 'Study area', 'Electricity/water included'], 'sbf-0012-jaffna-veranda-room.webp', $serendib, 4.8, 15, 37, 322),
            $this->listing('Compact City Room in Colombo 03', 'A polished compact room for a city professional, with smart storage, a private hot-water bathroom and round-the-clock building security. Kollupitiya offices, buses, trains and supermarkets are within walking distance.', 'private_room', 42000, 40000, 'Colombo 03', 'Colombo', 'Colombo', 6.9141, 79.8524, 'any', 1, false, true, ['WiFi', 'Air conditioning', 'Security/CCTV', 'Attached bathroom', 'Hot water'], 'sbf-0013-colombo-compact-room.webp', $serendib, 4.9, 19, 52, 463),
            $this->listing('Rooftop Annex in Rajagiriya', 'A light-filled rooftop annex with its own kitchen, shaded terrace and skyline views, finished for comfortable long stays. Gated parking and quick links to Parliament Road make it useful for office commuters.', 'annex', 45000, 40000, 'Rajagiriya', 'Sri Jayawardenepura Kotte', 'Colombo', 6.9094, 79.8943, 'any', 2, true, true, ['WiFi', 'Air conditioning', 'Kitchen access', 'Parking', 'Hot water'], 'sbf-0014-rajagiriya-rooftop-annex.webp', $serendib, 4.8, 17, 46, 394),
            $this->listing('Temple Road Shared Student House', 'An affordable shared home near Kelaniya with two-person rooms, a welcoming common veranda and optional home-cooked meals. The university, railway station and Colombo buses are straightforward to reach.', 'shared_room', 18000, 10000, 'Kelaniya', 'Kelaniya', 'Gampaha', 6.9751, 79.9157, 'female_only', 2, true, true, ['WiFi', 'Fan', 'Meals', 'Study area', 'Electricity/water included'], 'sbf-0015-kelaniya-shared-house.webp', $serendib, 4.5, 21, 50, 429),
            $this->listing('Clinical Placement Room in Ragama', 'A clean room arranged for medical and nursing students, with a quiet desk, hot water and secure parking for irregular shift hours. Ragama Teaching Hospital and the railway station are nearby.', 'private_room', 23000, 15000, 'Ragama', 'Ragama', 'Gampaha', 7.0284, 79.9214, 'any', 1, false, true, ['WiFi', 'Hot water', 'Parking', 'Study area', 'Laundry'], 'sbf-0016-ragama-clinical-room.webp', $serendib, 4.7, 14, 34, 296),
            $this->listing('Lagoon Breeze Boarding Room', 'An airy east-coast boarding room with woven details, a broad window and a work desk cooled by the lagoon breeze. Batticaloa town, Eastern University transport and daily essentials are close.', 'boarding_room', 22000, 12000, 'Batticaloa Town', 'Batticaloa', 'Batticaloa', 7.7170, 81.7000, 'any', 1, false, true, ['WiFi', 'Fan', 'Study area', 'Attached bathroom', 'Electricity/water included'], 'sbf-0017-batticaloa-lagoon-room.webp', $serendib, 4.6, 8, 24, 188),
            $this->listing('Coastal Courtyard Annex', 'A private Trincomalee annex opening to a breezy tiled courtyard, with air conditioning, a full kitchen and gated parking. It is positioned for town offices, the harbour area and coastal transport.', 'annex', 31000, 25000, 'Trincomalee Town', 'Trincomalee', 'Trincomalee', 8.5874, 81.2152, 'any', 2, true, true, ['WiFi', 'Air conditioning', 'Parking', 'Kitchen access', 'Attached bathroom'], 'sbf-0018-trincomalee-courtyard-annex.webp', $serendib, 4.8, 9, 29, 231),
            $this->listing('Heritage Garden Bodim', 'A simple, peaceful bodim in an established Anuradhapura garden, featuring a shaded veranda, strong cross-ventilation and secure parking. Town buses, the teaching hospital and supermarket access are convenient.', 'boarding_room', 20000, 12000, 'Anuradhapura Town', 'Anuradhapura', 'Anuradhapura', 8.3114, 80.4037, 'male_only', 1, false, true, ['WiFi', 'Fan', 'Parking', 'Study area', 'Electricity/water included'], 'sbf-0019-anuradhapura-garden-bodim.webp', $hillCountry, 4.6, 12, 32, 267),
            $this->listing('Misty Hill Boarding Room', 'A cosy Badulla room with hill-country light, timber furniture and dependable hot water, suited to a student or public-sector professional. The town, hospital and railway station are accessible by a short commute.', 'private_room', 25000, 15000, 'Badulla Town', 'Badulla', 'Badulla', 6.9934, 81.0550, 'any', 1, false, true, ['WiFi', 'Hot water', 'Study area', 'Attached bathroom', 'Laundry'], 'sbf-0020-badulla-misty-hill-room.webp', $hillCountry, 4.8, 13, 36, 309),
            $this->listing('Southern Veranda Studio', 'A fresh Matara studio with a deep shaded veranda, patterned cement floor and a compact private bathroom. Air conditioning and off-street parking make it comfortable for a professional or couple.', 'studio', 29000, 22000, 'Matara Town', 'Matara', 'Matara', 5.9549, 80.5550, 'any', 2, true, true, ['WiFi', 'Air conditioning', 'Parking', 'Attached bathroom', 'Kitchen access'], 'sbf-0021-matara-veranda-studio.webp', $serendib, 4.7, 11, 38, 318),
            $this->listing('Rubber Estate Cottage Annex', 'A small independent cottage annex at the edge of a Kegalle rubber garden, with a private kitchen and space for a vehicle. It offers quiet evenings while remaining connected to Kegalle town buses.', 'annex', 26000, 18000, 'Kegalle Town', 'Kegalle', 'Kegalle', 7.2513, 80.3464, 'any', 2, true, true, ['WiFi', 'Kitchen access', 'Parking', 'Hot water', 'Fan'], 'sbf-0022-kegalle-cottage-annex.webp', $hillCountry, 4.6, 7, 22, 171),
            $this->listing('Commuter Garden Room', 'A well-kept Gampaha room with a study alcove, air conditioning and gated parking in a leafy commuter neighbourhood. The railway station and express Colombo connections are a short ride away.', 'private_room', 23500, 15000, 'Gampaha Town', 'Gampaha', 'Gampaha', 7.0873, 80.0144, 'any', 1, false, true, ['WiFi', 'Air conditioning', 'Parking', 'Study area', 'Security/CCTV'], 'sbf-0023-gampaha-commuter-room.webp', $serendib, 4.8, 16, 41, 347),
            $this->listing('Lagoon Town Studio', 'A stylish private studio inspired by Negombo coastal homes, with a cane reading chair, kitchenette and cooling air conditioning. It is convenient for airport-sector work, the railway station and lagoon-side dining.', 'studio', 36000, 30000, 'Negombo Town', 'Negombo', 'Gampaha', 7.2083, 79.8358, 'any', 2, true, true, ['WiFi', 'Air conditioning', 'Kitchen access', 'Parking', 'Attached bathroom'], 'sbf-0024-negombo-lagoon-studio.webp', $serendib, 4.9, 18, 47, 402),
        ];
    }

    private function listing(string $title, string $description, string $propertyType, int $price, int $deposit, string $area, string $city, string $district, float $latitude, float $longitude, string $genderRule, int $occupancy, bool $sharing, bool $furnished, array $facilities, string $image, string $ownerEmail, float $rating, int $reviews, int $favorites, int $views): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'area' => $area,
            'city' => $city,
            'district' => $district,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'property_type' => $propertyType,
            'monthly_price_lkr' => $price,
            'deposit_lkr' => $deposit,
            'gender_rule' => $genderRule,
            'occupancy_limit' => $occupancy,
            'sharing_allowed' => $sharing,
            'furnished' => $furnished,
            'facilities' => $facilities,
            'image' => $image,
            'house_rules' => 'No smoking. Respect neighbours and quiet hours after 10 PM. Visitors require prior owner approval.',
            'average_rating' => $rating,
            'review_count' => $reviews,
            'favorite_count' => $favorites,
            'view_count' => $views,
            'owner_email' => $ownerEmail,
        ];
    }

    private function seedInteractions($listings, User $tenant): void
    {
        $reviews = [
            [0, 5, 'Exactly as shown: a bright study room, reliable WiFi and a helpful owner. The campus commute was very easy.'],
            [1, 5, 'The courtyard keeps the annex cool and peaceful. Nugegoda buses and shopping are genuinely convenient.'],
            [2, 4, 'Clean private studio with good security and a short walk to the train. Evening road noise is noticeable.'],
            [6, 5, 'Beautiful hill view, strong hot water and a quiet desk for remote work. The room was spotless.'],
            [11, 5, 'The veranda and ventilation are excellent for Jaffna weather. Owner communication was clear and respectful.'],
        ];

        foreach ($reviews as [$index, $rating, $body]) {
            Review::query()->create(['tenant_id' => $tenant->id, 'listing_id' => $listings[$index]->id, 'rating' => $rating, 'body' => $body, 'moderation_status' => 'visible']);
        }

        foreach ([1, 4, 11] as $index) {
            DB::table('favorites')->insert(['user_id' => $tenant->id, 'listing_id' => $listings[$index]->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $listing = $listings[1];
        $conversation = Conversation::query()->create(['listing_id' => $listing->id, 'tenant_id' => $tenant->id, 'owner_id' => $listing->owner_id, 'subject' => 'Availability in September']);
        $conversation->messages()->createMany([
            ['sender_id' => $tenant->id, 'body' => 'Hello, could I view the annex this Saturday afternoon?'],
            ['sender_id' => $listing->owner_id, 'body' => 'Yes, Saturday at 3 PM is available. I will send the exact meeting point.'],
        ]);
    }

    private function seedNearbyPlaces($listings): void
    {
        $places = collect($this->nearbyPlaceCatalogue())->groupBy(fn (array $place) => $place[0]);

        foreach ($listings as $listing) {
            foreach ($places as $type => $candidates) {
                $nearest = $candidates->sortBy(fn (array $place) => $this->distanceKm((float) $listing->latitude, (float) $listing->longitude, $place[2], $place[3]))->first();
                ListingNearbyPlace::query()->create([
                    'listing_id' => $listing->id,
                    'type' => $type,
                    'name' => $nearest[1],
                    'distance_m' => (int) round($this->distanceKm((float) $listing->latitude, (float) $listing->longitude, $nearest[2], $nearest[3]) * 1000),
                    'latitude' => $nearest[2],
                    'longitude' => $nearest[3],
                    'source_provider' => 'project-fixture',
                    'source_reference' => 'datasets/README.md#seeded-public-listing-corpus',
                    'coordinate_confidence' => 0.60,
                    'verified_at' => now(),
                ]);
            }
        }
    }

    private function nearbyPlaceCatalogue(): array
    {
        $areas = [
            ['Moratuwa', 6.7735, 79.8818, 'Moratuwa Railway Station', 'Cargills Food City Moratuwa', 'Lunawa District Hospital', 'Moratuwa town dining area'],
            ['Katubedda', 6.7978, 79.9009, 'Angulana Railway Station', 'Cargills Food City Katubedda', 'University Medical Centre Moratuwa', 'Katubedda Junction cafés'],
            ['Nugegoda', 6.8721, 79.8899, 'Nugegoda Railway Station', 'Cargills Food City Nugegoda', 'Colombo South Teaching Hospital', 'Nugegoda town food court'],
            ['Dehiwala', 6.8513, 79.8655, 'Dehiwala Railway Station', 'Cargills Food City Dehiwala', 'Kalubowila Teaching Hospital', 'Dehiwala Junction dining area'],
            ['Malabe', 6.9043, 79.9548, 'Kottawa Railway Station', 'Cargills Food City Malabe', 'Neville Fernando Teaching Hospital', 'Malabe Junction dining area'],
            ['Homagama', 6.8442, 80.0032, 'Homagama Railway Station', 'Cargills Food City Homagama', 'Homagama Base Hospital', 'Homagama town dining area'],
            ['Kandy', 7.2902, 80.6318, 'Kandy Railway Station', 'Cargills Food City Kandy', 'National Hospital Kandy', 'Kandy City Centre food court'],
            ['Peradeniya', 7.2634, 80.5935, 'Peradeniya Junction Railway Station', 'Cargills Food City Peradeniya', 'Teaching Hospital Peradeniya', 'Peradeniya Junction dining area'],
            ['Galle', 6.0351, 80.2149, 'Galle Railway Station', 'Cargills Food City Galle', 'Teaching Hospital Karapitiya', 'Galle Fort dining precinct'],
            ['Karapitiya', 6.0680, 80.2260, 'Galle Railway Station', 'Cargills Food City Karapitiya', 'Teaching Hospital Karapitiya', 'Karapitiya food court'],
            ['Kurunegala', 7.4867, 80.3631, 'Kurunegala Railway Station', 'Cargills Food City Kurunegala', 'Teaching Hospital Kurunegala', 'Kurunegala town dining area'],
            ['Jaffna', 9.6650, 80.0107, 'Jaffna Railway Station', 'Cargills Food City Jaffna', 'Teaching Hospital Jaffna', 'Jaffna town dining area'],
            ['Colombo 03', 6.9116, 79.8514, 'Kollupitiya Railway Station', 'Cargills Food City Colombo 03', 'National Hospital of Sri Lanka', 'Kollupitiya dining area'],
            ['Rajagiriya', 6.9090, 79.8950, 'Cotta Road Railway Station', 'Cargills Food City Rajagiriya', 'Sri Jayewardenepura General Hospital', 'Rajagiriya food street'],
            ['Kelaniya', 6.9740, 79.9160, 'Kelaniya Railway Station', 'Cargills Food City Kelaniya', 'Kiribathgoda Base Hospital', 'Kelaniya Junction dining area'],
            ['Ragama', 7.0288, 79.9217, 'Ragama Railway Station', 'Cargills Food City Ragama', 'Colombo North Teaching Hospital', 'Ragama town dining area'],
            ['Batticaloa', 7.7167, 81.7001, 'Batticaloa Railway Station', 'Cargills Food City Batticaloa', 'Teaching Hospital Batticaloa', 'Batticaloa town dining area'],
            ['Trincomalee', 8.5870, 81.2150, 'Trincomalee Railway Station', 'Cargills Food City Trincomalee', 'District General Hospital Trincomalee', 'Central Road dining area'],
            ['Anuradhapura', 8.3120, 80.4040, 'Anuradhapura Railway Station', 'Cargills Food City Anuradhapura', 'Teaching Hospital Anuradhapura', 'New Town dining area'],
            ['Badulla', 6.9930, 81.0555, 'Badulla Railway Station', 'Cargills Food City Badulla', 'Provincial General Hospital Badulla', 'Badulla town dining area'],
            ['Matara', 5.9550, 80.5547, 'Matara Railway Station', 'Cargills Food City Matara', 'District General Hospital Matara', 'Matara Fort dining area'],
            ['Kegalle', 7.2510, 80.3460, 'Rambukkana Railway Station', 'Cargills Food City Kegalle', 'Kegalle General Hospital', 'Kegalle town dining area'],
            ['Gampaha', 7.0870, 80.0140, 'Gampaha Railway Station', 'Cargills Food City Gampaha', 'District General Hospital Gampaha', 'Gampaha town dining area'],
            ['Negombo', 7.2080, 79.8360, 'Negombo Railway Station', 'Cargills Food City Negombo', 'District General Hospital Negombo', 'Negombo town dining area'],
        ];

        return collect($areas)->flatMap(function (array $area): array {
            [$name, $lat, $lng, $train, $market, $hospital, $food] = $area;

            return [
                ['bus_station', $name.' central bus stop', $lat + .0010, $lng + .0007],
                ['train_station', $train, $lat - .0006, $lng - .0008],
                ['supermarket', $market, $lat + .0005, $lng + .0010],
                ['hospital', $hospital, $lat + .0018, $lng + .0015],
                ['food', $food, $lat - .0008, $lng + .0006],
                ['pharmacy', $name.' community pharmacy', $lat + .0012, $lng - .0004],
                ['bank_atm', $name.' bank and ATM', $lat - .0011, $lng + .0012],
                ['police', $name.' Police Station', $lat + .0015, $lng - .0010],
                ['laundry', $name.' laundry service', $lat - .0014, $lng - .0005],
            ];
        })->all();
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return 6371.0088 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
