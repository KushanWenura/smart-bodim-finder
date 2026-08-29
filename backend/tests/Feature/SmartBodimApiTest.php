<?php

namespace Tests\Feature;

use App\Jobs\SynchronizeListingIndex;
use App\Models\Listing;
use App\Models\User;
use App\Services\AiServiceClient;
use App\Services\ListingRiskService;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SmartBodimApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_password_recovery_keeps_account_discovery_private_and_resets_a_valid_account(): void
    {
        Notification::fake();
        $user = User::where('role', 'tenant')->firstOrFail();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'If that account exists, a reset link has been sent.');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If that account exists, a reset link has been sent.');
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'FreshPass123',
            'password_confirmation' => 'FreshPass123',
        ])->assertOk()->assertJsonPath('message', 'Password reset successfully.');

        $this->assertTrue(Hash::check('FreshPass123', $user->fresh()->password));
    }

    public function test_seeded_catalogue_contains_24_unique_sri_lankan_listings_and_local_photos(): void
    {
        $this->assertSame(24, Listing::count());
        $this->assertSame(24, Listing::query()->distinct()->count('title'));
        $this->assertSame(24, Listing::query()->distinct()->count('description'));
        $this->assertSame(24, DB::table('listing_images')->count());
        $this->assertSame(24, DB::table('listing_images')->distinct()->count('storage_path'));
        $this->assertSame(216, DB::table('listing_nearby_places')->count());

        DB::table('listing_images')->pluck('storage_path')->each(function (string $path): void {
            $this->assertStringStartsWith('/images/listings/', $path);
            $this->assertFileExists(public_path(ltrim($path, '/')));
        });
    }

    public function test_public_listing_filters_only_return_eligible_results(): void
    {
        $response = $this->getJson('/api/v1/listings?city=Colombo&maxPrice=30000&perPage=5')->assertOk();
        collect($response->json('data'))->each(fn ($row) => $this->assertTrue($row['city'] === 'Colombo' && $row['price'] <= 30000 && $row['status'] === 'published'));
    }

    public function test_tenant_registration_creates_profile_and_hashes_password(): void
    {
        $this->postJson('/api/v1/auth/register', ['role' => 'tenant', 'name' => 'New Tenant', 'email' => 'new@example.com', 'phone' => '0779998888', 'password' => 'StrongPass1', 'password_confirmation' => 'StrongPass1'])->assertCreated();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('tenant', $user->role);
        $this->assertTrue(Hash::check('StrongPass1', $user->password));
        $this->assertDatabaseHas('tenant_profiles', ['user_id' => $user->id]);
    }

    public function test_tenant_cannot_access_admin_endpoints(): void
    {
        $tenant = User::where('role', 'tenant')->first();
        $this->actingAs($tenant)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_favorites_are_idempotent(): void
    {
        $tenant = User::where('role', 'tenant')->first();
        $listing = Listing::where('status', 'published')->first();
        $this->actingAs($tenant)->putJson("/api/v1/favorites/{$listing->id}")->assertOk();
        $this->putJson("/api/v1/favorites/{$listing->id}")->assertOk();
        $this->assertSame(1, DB::table('favorites')->where(['user_id' => $tenant->id, 'listing_id' => $listing->id])->count());
        $this->getJson("/api/v1/listings/{$listing->id}")->assertOk()->assertJsonPath('favorite', true);
        $this->deleteJson("/api/v1/favorites/{$listing->id}")->assertOk();
        $this->getJson("/api/v1/listings/{$listing->id}")->assertOk()->assertJsonPath('favorite', false);
    }

    public function test_assistant_understands_maharagama_as_a_nearby_area_request(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'fixture-tfidf', 'results' => []])]);

        $response = $this->postJson('/api/v1/assistant/chat', [
            'message' => 'Please find any bodim near Maharagama town',
        ])->assertOk()
            ->assertJsonPath('interpreted.location', 'Maharagama')
            ->assertJsonPath('search.mode', 'structured-nearby-fallback');

        $this->assertNotEmpty($response->json('results'));
        collect($response->json('results'))->each(function (array $listing): void {
            $this->assertContains($listing['area'], ['Nugegoda', 'Homagama']);
            $this->assertSame('published', $listing['status']);
            $this->assertArrayNotHasKey('privateAddress', $listing);
        });
    }

    public function test_destination_search_orders_verified_listings_by_distance(): void
    {
        $response = $this->getJson('/api/v1/proximity?destination=University%20of%20Moratuwa%20Katubedda&radiusKm=20')
            ->assertOk()
            ->assertJsonPath('destination.name', 'University of Moratuwa - Katubedda')
            ->assertJsonPath('meta.distanceMethod', 'Haversine eligibility radius');

        $rows = collect($response->json('data'));
        $this->assertNotEmpty($rows);
        $this->assertSame($rows->pluck('distanceKm')->sort()->values()->all(), $rows->pluck('distanceKm')->all());
        $rows->each(function (array $listing): void {
            $this->assertLessThanOrEqual(20, $listing['distanceKm']);
            $this->assertNotEmpty($listing['nearbyPlaces']);
            $places = collect($listing['nearbyPlaces']);
            $this->assertEqualsCanonicalizing(['bus_station', 'train_station', 'supermarket', 'hospital', 'food', 'pharmacy', 'bank_atm', 'police', 'laundry'], $places->pluck('type')->all());
            $places->each(function (array $place): void {
                $this->assertIsNumeric($place['distanceM']);
                $this->assertIsNumeric($place['latitude']);
                $this->assertIsNumeric($place['longitude']);
            });
            $this->assertArrayNotHasKey('privateAddress', $listing);
        });
    }

    public function test_assistant_applies_campus_distance_and_returns_nearby_essentials(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'fixture-tfidf', 'results' => []])]);

        $response = $this->postJson('/api/v1/assistant/chat', [
            'message' => 'Find a WiFi room within 20 km of University of Moratuwa Katubedda under Rs. 40000',
        ])->assertOk()
            ->assertJsonPath('interpreted.destination.name', 'University of Moratuwa - Katubedda');

        $this->assertNotEmpty($response->json('results'));
        collect($response->json('results'))->each(function (array $listing): void {
            $this->assertLessThanOrEqual(20, $listing['distanceKm']);
            $this->assertNotEmpty($listing['nearbyPlaces']);
        });
    }

    public function test_assistant_strictly_matches_facility_aliases_and_returns_explainable_ranking(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'fixture-tfidf', 'results' => []])]);

        $response = $this->postJson('/api/v1/assistant/chat', [
            'message' => 'Near University of Moratuwa Katubedda with WiFi, AC and car park under Rs. 35,000',
        ])->assertOk()
            ->assertJsonPath('interpreted.destination.name', 'University of Moratuwa - Katubedda')
            ->assertJsonPath('interpreted.maxPrice', 35000)
            ->assertJsonPath('search.rankingMethod', 'strict filters followed by weighted suitability scoring')
            ->assertJsonPath('results.0.matchRank', 1)
            ->assertJsonPath('results.0.matchLabel', 'Best match');

        $this->assertEqualsCanonicalizing(['WiFi', 'Parking', 'Air conditioning'], $response->json('interpreted.facilities'));
        $this->assertNotEmpty($response->json('results'));
        $results = collect($response->json('results'));
        $this->assertSame(range(1, $results->count()), $results->pluck('matchRank')->all());
        $this->assertSame($results->pluck('matchScore')->sortDesc()->values()->all(), $results->pluck('matchScore')->all());
        $results->each(function (array $listing): void {
            $this->assertLessThanOrEqual(35000, $listing['price']);
            $this->assertEqualsCanonicalizing(
                ['WiFi', 'Parking', 'Air conditioning'],
                collect($listing['facilities'])->intersect(['WiFi', 'Parking', 'Air conditioning'])->values()->all()
            );
            $this->assertNotEmpty($listing['matchReasons']);
            $this->assertNotEmpty($listing['matchedRequirements']);
        });
    }

    public function test_assistant_understands_k_budget_occupancy_furnished_and_nearby_priorities(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'fixture-tfidf', 'results' => []])]);

        $response = $this->postJson('/api/v1/assistant/chat', [
            'message' => 'Find a furnished room near University of Moratuwa Katubedda for two people with WiFi under 35k near Cargills',
        ])->assertOk()
            ->assertJsonPath('interpreted.destination.name', 'University of Moratuwa - Katubedda')
            ->assertJsonPath('interpreted.maxPrice', 35000)
            ->assertJsonPath('interpreted.occupancy', 2)
            ->assertJsonPath('interpreted.furnished', true)
            ->assertJsonPath('interpreted.nearbyPriorities.0', 'supermarket')
            ->assertJsonCount(1, 'interpreted.nearbyPriorities');

        $this->assertNotEmpty($response->json('results'));
        $this->assertNotEmpty($response->json('followUps'));
        collect($response->json('results'))->each(function (array $listing): void {
            $this->assertLessThanOrEqual(35000, $listing['price']);
            $this->assertGreaterThanOrEqual(2, $listing['occupancy']);
            $this->assertTrue($listing['furnished']);
            $this->assertContains('WiFi', $listing['facilities']);
        });
    }

    public function test_assistant_uses_short_conversation_context_for_refinements(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'fixture-tfidf', 'results' => []])]);

        $response = $this->postJson('/api/v1/assistant/chat', [
            'message' => 'Make it cheaper and only within 5 km',
            'context' => ['Find a WiFi room near University of Moratuwa Katubedda under 35k'],
        ])->assertOk()
            ->assertJsonPath('interpreted.destination.name', 'University of Moratuwa - Katubedda')
            ->assertJsonPath('interpreted.maxPrice', 30000)
            ->assertJsonPath('interpreted.radiusKm', 5);

        $this->assertContains('WiFi', $response->json('interpreted.facilities'));
        collect($response->json('results'))->each(function (array $listing): void {
            $this->assertLessThanOrEqual(30000, $listing['price']);
            $this->assertLessThanOrEqual(5, $listing['distanceKm']);
        });
    }

    public function test_destination_directory_exposes_verified_icbt_branches(): void
    {
        $response = $this->getJson('/api/v1/destinations')->assertOk();
        $branches = collect($response->json('data'))->where('organizationName', 'ICBT Campus')->values();

        $this->assertCount(10, $branches);
        $this->assertContains('Colombo', $branches->pluck('branchName'));
        $this->assertContains('Kandy', $branches->pluck('branchName'));
        $this->assertContains('Jaffna', $branches->pluck('branchName'));
        $branches->each(fn (array $branch) => $this->assertNotEmpty($branch['name']));
    }

    public function test_destination_directory_covers_nationwide_public_and_non_state_networks(): void
    {
        $destinations = collect($this->getJson('/api/v1/destinations')->assertOk()->json('data'));
        $campuses = $destinations->where('type', 'campus');

        $this->assertCount(160, $destinations);
        $this->assertCount(152, $campuses);
        $this->assertCount(41, $campuses->pluck('organizationName')->unique());
        $this->assertCount(38, $campuses->where('organizationName', 'ESOFT Metro Campus'));
        $this->assertCount(27, $campuses->where('organizationName', 'Open University of Sri Lanka'));
        $this->assertCount(10, $campuses->where('organizationName', 'ICBT Campus'));
        $this->assertCount(8, $campuses->where('organizationName', 'Sri Lanka Institute of Information Technology'));
        $this->assertCount(8, $campuses->where('organizationName', 'National Institute of Business Management'));
        $this->assertCount(4, $campuses->where('organizationName', 'CINEC Campus'));
    }

    public function test_chatbot_clarifies_large_islandwide_branch_networks(): void
    {
        $this->postJson('/api/v1/assistant/chat', ['message' => 'Find a room near ESOFT'])
            ->assertOk()
            ->assertJsonPath('search.mode', 'branch-clarification')
            ->assertJsonCount(38, 'suggestions');
    }

    public function test_generic_multi_branch_destination_requires_clarification(): void
    {
        $response = $this->getJson('/api/v1/proximity?destination=ICBT%20Campus&radiusKm=15')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'ambiguous_destination')
            ->assertJsonPath('organization', 'ICBT Campus');

        $this->assertCount(10, $response->json('suggestions'));
    }

    public function test_exact_workplace_name_wins_over_generic_city_branch_aliases(): void
    {
        $this->getJson('/api/v1/proximity?destination=Kandy%20City%20Centre&radiusKm=15')
            ->assertOk()
            ->assertJsonPath('destination.name', 'Kandy City Centre')
            ->assertJsonPath('destination.type', 'workplace');
    }

    public function test_chatbot_offers_branch_buttons_instead_of_guessing(): void
    {
        $response = $this->postJson('/api/v1/assistant/chat', ['message' => 'Find a WiFi room near ICBT Campus under Rs. 35000'])
            ->assertOk()
            ->assertJsonPath('search.mode', 'branch-clarification')
            ->assertJsonPath('interpreted.destination.status', 'ambiguous');

        $this->assertEmpty($response->json('results'));
        $this->assertCount(10, $response->json('suggestions'));
        $this->assertStringContainsString('WiFi', $response->json('suggestions.0.query'));
        $this->assertStringContainsString('35000', $response->json('suggestions.0.query'));
        $this->assertStringContainsString($response->json('suggestions.0.name'), $response->json('suggestions.0.query'));
        $this->assertSame('ICBT Campus - Colombo', $response->json('suggestions.0.name'));
        $this->assertStringContainsString('will not choose one automatically', $response->json('answer'));
    }

    public function test_generic_moratuwa_query_requires_branch_choice_with_katubedda_first(): void
    {
        $response = $this->postJson('/api/v1/assistant/chat', [
            'message' => 'University of Moratuwa ලඟ WiFi සහ AC තියෙන room එකක් 35000ට අඩුවෙන් ඕන',
        ])->assertOk()
            ->assertJsonPath('search.mode', 'branch-clarification')
            ->assertJsonPath('interpreted.destination.status', 'ambiguous');

        $this->assertCount(2, $response->json('suggestions'));
        $this->assertSame('University of Moratuwa - Katubedda', $response->json('suggestions.0.name'));
        $this->assertSame('University of Moratuwa - Institute of Technology Diyagama', $response->json('suggestions.1.name'));
        $this->assertEmpty($response->json('results'));
    }

    public function test_chatbot_resolves_a_named_institution_branch(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'fixture-tfidf', 'results' => []])]);

        $this->postJson('/api/v1/assistant/chat', ['message' => 'Find a room within 20 km of ICBT Kandy under Rs. 50000'])
            ->assertOk()
            ->assertJsonPath('interpreted.destination.name', 'ICBT Campus - Kandy')
            ->assertJsonPath('interpreted.destination.branchName', 'Kandy');
    }

    public function test_owner_submission_and_admin_approval_are_audited(): void
    {
        Queue::fake();
        $owner = User::where('role', 'owner')->first();
        $listing = Listing::create(['owner_id' => $owner->id, 'public_slug' => 'SBF-TEST', 'title' => 'Complete test listing', 'description' => str_repeat('Valid property information. ', 3), 'property_type' => 'private_room', 'monthly_price_lkr' => 25000, 'public_area' => 'Malabe', 'city' => 'Colombo', 'district' => 'Colombo', 'latitude' => 6.9, 'longitude' => 79.95, 'gender_rule' => 'any', 'occupancy_limit' => 1, 'available' => true, 'status' => 'draft']);
        $listing->images()->create(['storage_path' => 'test.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 100, 'alt_text' => 'Test room', 'sort_order' => 0, 'is_cover' => true]);
        $this->actingAs($owner)->postJson("/api/v1/owner/listings/{$listing->id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->postJson("/api/v1/admin/listings/{$listing->id}/approve", ['reason' => 'All listing evidence and public details were verified.'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $listing->id, 'new_status' => 'published']);
        $this->assertDatabaseHas('admin_audit_logs', ['target_id' => $listing->id, 'action' => 'listing.published']);
    }

    public function test_owner_can_securely_edit_a_published_listing_and_admin_can_review_changes(): void
    {
        Queue::fake();
        $owner = User::where('role', 'owner')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $listing = Listing::where('owner_id', $owner->id)->where('status', 'published')->with('facilities')->firstOrFail();
        $listing->update(['private_address' => '42 Test Lane, Katubedda']);

        $this->actingAs($owner)->getJson("/api/v1/owner/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonPath('data.privateAddress', '42 Test Lane, Katubedda');

        $otherOwner = User::create([
            'role' => 'owner', 'name' => 'Other Property Owner', 'email' => 'other-owner@example.test',
            'phone' => '0770000099', 'password' => Hash::make('Owner@123'), 'status' => 'active',
        ]);
        $this->actingAs($otherOwner)->getJson("/api/v1/owner/listings/{$listing->id}")->assertForbidden();

        $payload = [
            'title' => $listing->title.' Updated', 'description' => $listing->description,
            'propertyType' => $listing->property_type, 'price' => $listing->monthly_price_lkr + 1000,
            'deposit' => $listing->deposit_lkr, 'privateAddress' => $listing->private_address,
            'area' => $listing->public_area, 'city' => $listing->city, 'district' => $listing->district,
            'latitude' => (float) $listing->latitude, 'longitude' => (float) $listing->longitude,
            'genderRule' => $listing->gender_rule, 'occupancy' => $listing->occupancy_limit,
            'availableFrom' => $listing->available_from?->toDateString(), 'sharingAllowed' => (bool) $listing->sharing_allowed,
            'furnished' => (bool) $listing->furnished, 'houseRules' => $listing->house_rules,
            'facilityIds' => $listing->facilities->pluck('id')->all(),
        ];

        $this->actingAs($owner)->putJson("/api/v1/owner/listings/{$listing->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'change_pending')
            ->assertJsonPath('data.title', $payload['title']);
        $this->assertDatabaseHas('listing_status_history', ['listing_id' => $listing->id, 'previous_status' => 'published', 'new_status' => 'change_pending']);

        $this->actingAs($admin)->postJson("/api/v1/admin/listings/{$listing->id}/approve", ['reason' => 'Updated public details and facilities were verified.'])
            ->assertOk()->assertJsonPath('data.status', 'published');

        $payload['title'] .= ' Again';
        $this->actingAs($owner)->putJson("/api/v1/owner/listings/{$listing->id}", $payload)->assertOk()->assertJsonPath('data.status', 'change_pending');
        $this->actingAs($admin)->postJson("/api/v1/admin/listings/{$listing->id}/reject", ['reason' => 'Please correct the updated public description.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected_changes');
        $this->actingAs($owner)->postJson("/api/v1/owner/listings/{$listing->id}/submit")
            ->assertOk()->assertJsonPath('data.status', 'change_pending');
    }

    public function test_non_participant_cannot_read_or_send_conversation_messages(): void
    {
        $admin = User::where('role', 'admin')->first();
        $conversation = DB::table('conversations')->first();
        $this->actingAs($admin)->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['text' => 'Unauthorized message'])->assertForbidden();
    }

    public function test_tenant_review_is_unique_and_recalculates_rating(): void
    {
        Queue::fake();
        $tenant = User::where('role', 'tenant')->first();
        $listing = Listing::where('status', 'published')->whereDoesntHave('reviews', fn ($q) => $q->where('tenant_id', $tenant->id))->first();
        $this->actingAs($tenant)->postJson('/api/v1/reviews', ['listingId' => $listing->id, 'rating' => 5, 'text' => 'A clean, safe and quiet place with reliable WiFi.'])->assertCreated();
        $this->postJson('/api/v1/reviews', ['listingId' => $listing->id, 'rating' => 4, 'text' => 'Updated review with clear and useful information.'])->assertCreated();
        $this->assertSame(1, DB::table('reviews')->where(['tenant_id' => $tenant->id, 'listing_id' => $listing->id])->count());
        $this->assertSame(4.0, (float) $listing->fresh()->average_rating);
    }

    public function test_ai_failure_keeps_search_available_with_fallback(): void
    {
        $this->mock(AiServiceClient::class, function ($mock): void {
            $mock->shouldReceive('search')->once()->andReturn([
                'online' => false,
                'mode' => 'keyword-fallback',
                'warning' => 'AI search is temporarily unavailable. Structured filters remain active.',
                'results' => [['id' => Listing::publiclyVisible()->firstOrFail()->id, 'score' => 1.0]],
            ]);
        });
        $response = $this->getJson('/api/v1/search?q=quiet%20room%20with%20WiFi&maxPrice=40000')->assertOk();
        $this->assertFalse($response->json('search.aiOnline'));
        $this->assertSame('keyword-fallback', $response->json('search.mode'));
    }

    public function test_admin_cannot_suspend_their_own_account(): void
    {
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->postJson("/api/v1/admin/users/{$admin->id}/status", ['status' => 'suspended', 'reason' => 'This should never be allowed.'])->assertUnprocessable();
    }

    public function test_public_registration_cannot_escalate_to_admin(): void
    {
        $this->postJson('/api/v1/auth/register', ['role' => 'admin', 'name' => 'Injected Admin', 'email' => 'bad@example.com', 'phone' => '0771234567', 'password' => 'StrongPass1', 'password_confirmation' => 'StrongPass1'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'bad@example.com']);
    }

    public function test_suspended_account_is_blocked_even_with_existing_session(): void
    {
        $tenant = User::where('role', 'tenant')->first();
        $tenant->update(['status' => 'suspended']);
        $this->actingAs($tenant)->getJson('/api/v1/notifications')->assertForbidden();
    }

    public function test_upload_rejects_executable_disguised_as_image(): void
    {
        Storage::fake('public');
        $owner = User::where('role', 'owner')->first();
        $listing = Listing::where('owner_id', $owner->id)->where('status', 'draft')->first() ?? Listing::where('owner_id', $owner->id)->first();
        $payload = UploadedFile::fake()->create('room.php.jpg', 20, 'application/x-php');
        $this->actingAs($owner)->post("/api/v1/owner/listings/{$listing->id}/images", ['image' => $payload], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_sql_injection_like_search_input_is_treated_as_plain_text(): void
    {
        $this->mock(AiServiceClient::class, function ($mock): void {
            $mock->shouldReceive('search')->once()->andReturn([
                'online' => false,
                'mode' => 'keyword-fallback',
                'warning' => 'AI search is temporarily unavailable. Structured filters remain active.',
                'results' => [],
            ]);
        });
        $this->getJson("/api/v1/search?q='%20OR%201=1--")->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'admin@smartbodim.lk']);
    }

    public function test_php_to_ai_search_contract_uses_ranked_ids(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'huggingface-faiss', 'modelVersion' => 'test-model', 'results' => [['id' => 2, 'score' => 0.91]]])]);
        $result = app(AiServiceClient::class)->search('quiet WiFi room', [['id' => 2, 'title' => 'Quiet room']]);
        $this->assertTrue($result['online']);
        $this->assertSame('huggingface-faiss', $result['mode']);
        $this->assertSame(2, $result['results'][0]['id']);
    }

    public function test_listing_index_job_records_success_without_changing_publication(): void
    {
        Http::fake(['*/v1/index/upsert' => Http::response(['status' => 'indexed', 'indexSize' => 1])]);
        $listing = Listing::where('status', 'published')->firstOrFail();
        (new SynchronizeListingIndex($listing->id))->handle(app(AiServiceClient::class));
        $this->assertDatabaseHas('ai_index_records', ['listing_id' => $listing->id, 'status' => 'indexed']);
        $this->assertSame('published', $listing->fresh()->status);
    }

    public function test_synchronous_index_rebuild_replaces_the_ai_index_with_only_public_listings(): void
    {
        Http::fake(['*/v1/index/rebuild' => Http::response(['status' => 'indexed', 'indexSize' => Listing::publiclyVisible()->count()])]);

        $this->artisan('ai:index-rebuild', ['--sync' => true])->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $payload = $request->data()['listings'] ?? [];

            return str_ends_with($request->url(), '/v1/index/rebuild')
                && count($payload) === Listing::publiclyVisible()->count()
                && collect($payload)->every(fn (array $listing) => isset($listing['id'], $listing['title'], $listing['facilities']));
        });
    }

    public function test_listing_reports_are_deduplicated_per_tenant(): void
    {
        $tenant = User::where('role', 'tenant')->firstOrFail();
        $listing = Listing::where('status', 'published')->firstOrFail();
        $this->actingAs($tenant)->postJson("/api/v1/listings/{$listing->id}/report", ['reason' => 'The public description appears misleading.'])->assertCreated();
        $this->postJson("/api/v1/listings/{$listing->id}/report", ['reason' => 'Updated report with clearer supporting detail.'])->assertCreated();
        $this->assertSame(1, DB::table('listing_reports')->where(['listing_id' => $listing->id, 'reporter_id' => $tenant->id])->count());
    }

    public function test_conversation_archive_is_per_participant(): void
    {
        $conversation = DB::table('conversations')->first();
        $tenant = User::findOrFail($conversation->tenant_id);
        $owner = User::findOrFail($conversation->owner_id);
        $this->actingAs($tenant)->postJson("/api/v1/conversations/{$conversation->id}/archive")->assertOk();
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonMissing(['id' => $conversation->id]);
        $this->actingAs($owner)->getJson('/api/v1/conversations')->assertOk()->assertJsonFragment(['id' => $conversation->id]);
    }

    public function test_admin_global_search_excludes_private_message_bodies(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $response = $this->actingAs($admin)->getJson('/api/v1/admin/search?q=Availability')->assertOk()->assertJsonFragment(['subject' => 'Availability in September']);
        $this->assertStringNotContainsString('arrange a viewing this weekend', $response->getContent());
    }

    public function test_query_understanding_separates_hard_preferred_and_excluded_facilities(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'fixture-tfidf', 'results' => []])]);

        $response = $this->postJson('/api/v1/assistant/chat', [
            'message' => 'Near University of Moratuwa Katubedda I must have WiFi, prefer AC, without meals, under Rs. 50000',
        ])->assertOk()
            ->assertJsonPath('interpreted.facilities.0', 'WiFi')
            ->assertJsonPath('interpreted.preferredFacilities.0', 'Air conditioning')
            ->assertJsonPath('interpreted.excludedFacilities.0', 'Meals')
            ->assertJsonPath('understanding.language', 'en');

        collect($response->json('results'))->each(function (array $listing): void {
            $this->assertContains('WiFi', $listing['facilities']);
            $this->assertNotContains('Meals', $listing['facilities']);
        });
    }

    public function test_query_understanding_accepts_sinhala_english_code_mixed_requirements(): void
    {
        Http::fake(['*/v1/search' => Http::response(['mode' => 'fixture-tfidf', 'results' => []])]);

        $this->postJson('/api/v1/assistant/chat', [
            'message' => 'University of Moratuwa Katubedda ළඟ අයවැය 40000 වයිෆයි සහ ඒසී බෝඩිමක්',
        ])->assertOk()
            ->assertJsonPath('understanding.language', 'si-en')
            ->assertJsonPath('interpreted.maxPrice', 40000)
            ->assertJsonPath('interpreted.destination.name', 'University of Moratuwa - Katubedda');
    }

    public function test_commute_results_expose_transparent_multimodal_estimates(): void
    {
        $listing = $this->getJson('/api/v1/proximity?destination=University%20of%20Moratuwa%20Katubedda&radiusKm=20')
            ->assertOk()
            ->assertJsonPath('meta.commuteModes.0', 'walking')
            ->json('data.0');

        $this->assertArrayHasKey('walking', $listing['commuteOptions']);
        $this->assertArrayHasKey('driving', $listing['commuteOptions']);
        $this->assertArrayHasKey('publicTransport', $listing['commuteOptions']);
        $this->assertNotEmpty($listing['routeMethod']);
    }

    public function test_ai_feedback_is_private_to_tenant_and_updates_opt_in_learning(): void
    {
        $tenant = User::where('role', 'tenant')->firstOrFail();
        $listing = Listing::publiclyVisible()->firstOrFail();
        $response = $this->actingAs($tenant)->postJson('/api/v1/ai/feedback', [
            'event' => 'favorite', 'listingId' => $listing->id, 'position' => 1, 'matchScore' => 91,
        ])->assertCreated()->assertJsonPath('recorded', true);

        $this->assertDatabaseHas('ai_feedback', ['user_id' => $tenant->id, 'listing_id' => $listing->id, 'event_type' => 'favorite']);
        $this->assertSame(1, $response->json('signalCount'));
        $this->assertSame(1, $tenant->tenantProfile->fresh()->learned_preferences['signals']);
    }

    public function test_consented_evaluation_sample_removes_contact_details(): void
    {
        $tenant = User::where('role', 'tenant')->firstOrFail();
        $logId = DB::table('search_logs')->insertGetId([
            'user_id' => $tenant->id,
            'sanitized_query' => 'WiFi room near campus, call 0771234567 or me@example.com',
            'filters' => json_encode(['language' => 'en', 'facilities' => ['WiFi']]),
            'result_count' => 0,
            'mode' => 'assistant:test',
            'latency_ms' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($tenant)->postJson('/api/v1/ai/evaluation-samples', [
            'searchLogId' => $logId, 'candidateListingIds' => [], 'consentConfirmed' => true,
        ])->assertCreated();
        $sample = DB::table('ai_evaluation_samples')->where('search_log_id', $logId)->first();
        $this->assertStringNotContainsString('0771234567', $sample->anonymized_query);
        $this->assertStringNotContainsString('me@example.com', $sample->anonymized_query);
        $this->assertTrue((bool) $sample->consent_confirmed);
    }

    public function test_listing_risk_assessment_is_advisory_and_evidence_backed(): void
    {
        $listing = Listing::firstOrFail();
        $assessment = app(ListingRiskService::class)->assess($listing);

        $this->assertSame($listing->id, $assessment->listing_id);
        $this->assertIsArray($assessment->flags);
        $this->assertGreaterThanOrEqual(0, $assessment->risk_score);
        $this->assertLessThanOrEqual(100, $assessment->risk_score);
        $this->assertSame('complete', $assessment->status);
        $this->assertSame($listing->status, $listing->fresh()->status);
    }

    public function test_area_safety_insight_is_explainable_confidence_aware_and_privacy_safe(): void
    {
        $listing = Listing::publiclyVisible()->firstOrFail();
        $response = $this->getJson("/api/v1/listings/{$listing->id}/area-safety")
            ->assertOk()
            ->assertJsonPath('data.listingId', $listing->id)
            ->assertJsonPath('data.method.scoreEngine', 'Deterministic weighted evidence model')
            ->assertJsonStructure(['data' => [
                'score', 'label', 'summary', 'disclaimer', 'dataGaps', 'guidance',
                'confidence' => ['level', 'score', 'reason'],
                'dimensions' => [['key', 'label', 'score', 'weight', 'status', 'explanation']],
                'signals' => [['type', 'label', 'name', 'distanceM', 'sourceProvider', 'sourceConfidence', 'needsConfirmation']],
                'map' => ['latitude', 'longitude', 'privacy', 'highlightTypes'],
                'method' => ['name', 'version', 'scoreEngine', 'explanationEngine', 'trainingReadiness'],
            ]]);

        $payload = $response->json('data');
        $this->assertGreaterThanOrEqual(0, $payload['score']);
        $this->assertLessThanOrEqual(100, $payload['score']);
        $this->assertContains($payload['confidence']['level'], ['Low', 'Medium', 'High']);
        $this->assertNotEmpty($payload['dataGaps']);
        $this->assertStringContainsString('not a guarantee', mb_strtolower($payload['disclaimer']));
        $this->assertArrayNotHasKey('privateAddress', $payload);
        $this->assertSame(round((float) $listing->latitude, 3), $payload['map']['latitude']);
        $this->assertNotSame((float) $listing->latitude, $payload['map']['latitude']);
    }

    public function test_area_safety_insight_is_unavailable_for_non_public_listing(): void
    {
        $listing = Listing::firstOrFail();
        $listing->forceFill(['status' => 'draft'])->save();

        $this->getJson("/api/v1/listings/{$listing->id}/area-safety")->assertNotFound();
    }

    public function test_moderated_consented_safety_observations_require_a_minimum_sample_before_scoring(): void
    {
        Http::fake(['*/v1/safety/analyze' => Http::response([
            'reportCount' => 3,
            'verifiedReportCount' => 0,
            'themes' => [['key' => 'lighting', 'label' => 'Street lighting', 'supportive' => 2, 'concerns' => 1, 'mentions' => 3, 'verifiedMentions' => 0, 'direction' => 'supportive']],
            'concernCount' => 1,
            'languageMix' => ['en' => 3],
            'modelVersion' => 'buddy-safety-aspects-v1.0.0',
            'method' => 'Transparent multilingual safety-aspect extraction',
            'evidencePolicy' => 'Opinion themes are not crime statistics.',
        ])]);

        $listing = Listing::publiclyVisible()->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $tenants = User::factory()->count(3)->create(['role' => 'tenant', 'status' => 'active']);
        $payloads = [
            ['visitPeriod' => 'both', 'comment' => 'The main road was well lit, but the final lane became quiet after nine at night.'],
            ['visitPeriod' => 'evening', 'comment' => 'Buses run late and there are houses nearby, although one crossing has no pavement.'],
            ['visitPeriod' => 'day', 'comment' => 'A hospital is nearby and the road stayed dry after rain during my regular commute.'],
        ];

        foreach ($tenants as $index => $tenant) {
            $response = $this->actingAs($tenant)->postJson("/api/v1/listings/{$listing->id}/area-safety/reports", [
                'visitBasis' => $index === 2 ? 'regular_commute' : 'viewing',
                'visitPeriod' => $payloads[$index]['visitPeriod'],
                'visitedOn' => now()->subDays($index + 1)->toDateString(),
                'lightingRating' => 4,
                'transportRating' => 4,
                'publicActivityRating' => 3,
                'roadSafetyRating' => 3,
                'emergencyAccessRating' => 4,
                'comment' => $payloads[$index]['comment'],
                'consentForResearch' => true,
            ])->assertCreated()->assertJsonPath('data.moderation_status', 'pending');

            $reportId = $response->json('data.id');
            $this->actingAs($admin)->postJson("/api/v1/admin/area-safety-reports/{$reportId}/approve", [
                'reason' => 'Approved after checking privacy, provenance wording and content quality.',
            ])->assertOk()->assertJsonPath('data.moderation_status', 'visible');
        }

        $response = $this->getJson("/api/v1/listings/{$listing->id}/area-safety")->assertOk();
        $community = collect($response->json('data.dimensions'))->firstWhere('key', 'community_observations');
        $this->assertSame('available', $community['status']);
        $this->assertGreaterThanOrEqual(0, $community['score']);
        $this->assertSame(3, $response->json('data.communityInsights.moderatedReportCount'));
        $this->assertSame('Street lighting', $response->json('data.communityInsights.themes.0.label'));
        $this->assertDatabaseCount('area_safety_reports', 3);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'area_safety_report.approve']);
    }

    public function test_synthetic_safety_training_rows_are_not_database_evidence(): void
    {
        $listing = Listing::publiclyVisible()->firstOrFail();

        $this->assertDatabaseCount('area_safety_reports', 0);
        $response = $this->getJson("/api/v1/listings/{$listing->id}/area-safety")->assertOk();
        $this->assertSame(0, $response->json('data.communityInsights.moderatedReportCount'));
        $this->assertStringContainsString('synthetic corpus', $response->json('data.method.trainingReadiness'));
    }

    public function test_enquiry_viewing_and_reservation_follow_the_safe_rental_journey(): void
    {
        Queue::fake();
        $tenant = User::where('role', 'tenant')->firstOrFail();
        $listing = Listing::publiclyVisible()->firstOrFail();
        $owner = User::findOrFail($listing->owner_id);

        $conversationId = $this->actingAs($tenant)->postJson('/api/v1/conversations', [
            'listingId' => $listing->id,
            'text' => 'I would like to ask a few questions before arranging a viewing.',
        ])->assertCreated()->json('data.id');

        $this->assertTrue($listing->fresh()->available, 'An enquiry must never block a listing.');

        $viewingId = $this->postJson("/api/v1/conversations/{$conversationId}/viewings", [
            'proposedAt' => now('Asia/Colombo')->addDays(2)->setTime(12, 15)->utc()->toIso8601String(),
            'note' => 'I can visit after class.',
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');

        $this->assertTrue($listing->fresh()->available, 'A viewing request must not block a listing.');

        $this->actingAs($owner)->postJson("/api/v1/owner/viewings/{$viewingId}/accept")
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $safetyUrl = $this->actingAs($tenant)->postJson("/api/v1/viewings/{$viewingId}/safety-contact", [
            'emergencyContactName' => 'Trusted Contact', 'emergencyContactPhone' => '0774567890',
        ])->assertOk()->assertJsonStructure(['shareUrl'])->json('shareUrl');
        $shareToken = basename(parse_url($safetyUrl, PHP_URL_PATH));
        $this->getJson("/api/v1/visit-share/{$shareToken}")->assertOk()
            ->assertJsonPath('data.status', 'accepted')->assertJsonMissing(['emergencyContactPhone' => '0774567890']);
        $this->actingAs($tenant)->postJson("/api/v1/viewings/{$viewingId}/attendance/check-out")->assertStatus(409);
        $this->postJson("/api/v1/viewings/{$viewingId}/attendance/check-in")->assertOk()
            ->assertJsonPath('data.tenant_attendance', 'attended');
        $this->postJson("/api/v1/viewings/{$viewingId}/attendance/check-out")->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/owner/viewings/{$viewingId}/complete")
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $reservationId = $this->actingAs($tenant)->postJson("/api/v1/conversations/{$conversationId}/reservations", [
            'viewingId' => $viewingId,
            'moveInDate' => today()->addDays(7)->toDateString(),
            'moveOutDate' => today()->addMonths(6)->toDateString(),
            'occupants' => 1,
            'message' => 'The visit went well and I would like to reserve this room.',
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');

        $this->assertTrue($listing->fresh()->available, 'A pending request does not block the listing until owner approval.');

        $this->actingAs($owner)->postJson("/api/v1/owner/reservations/{$reservationId}/accept")
            ->assertOk()->assertJsonPath('data.status', 'held');
        $this->assertFalse($listing->fresh()->available, 'An accepted hold blocks overlapping reservations.');

        $this->actingAs($tenant)->postJson("/api/v1/reservations/{$reservationId}/confirm")
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertDatabaseHas('rental_agreements', ['reservation_id' => $reservationId, 'status' => 'pending_acceptance']);
        $this->getJson("/api/v1/reservations/{$reservationId}/agreement")->assertOk()
            ->assertJsonPath('data.reservation_id', $reservationId);
        $this->postJson("/api/v1/reservations/{$reservationId}/agreement/accept", ['confirm' => true])
            ->assertOk()->assertJsonPath('data.status', 'pending_acceptance');
        $this->actingAs($owner)->postJson("/api/v1/reservations/{$reservationId}/agreement/accept", ['confirm' => true])
            ->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->get("/api/v1/reservations/{$reservationId}/agreement.pdf")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($tenant)->postJson("/api/v1/reservations/{$reservationId}/disputes", [
            'category' => 'property_condition',
            'details' => 'The condition during follow-up differed from the documented agreement and needs administrator review.',
        ])->assertCreated();
        $this->postJson("/api/v1/reservations/{$reservationId}/disputes", [
            'category' => 'payment', 'details' => 'A second duplicate report must not be silently accepted by the system.',
        ])->assertStatus(409);
        $this->getJson("/api/v1/listings/{$listing->id}")
            ->assertOk()->assertJsonPath('data.availabilityStatus', 'reserved');
    }

    public function test_owner_availability_rules_and_tenant_decision_support_are_enforced(): void
    {
        $listing = Listing::publiclyVisible()->firstOrFail();
        $owner = User::findOrFail($listing->owner_id);
        $tenant = User::where('role', 'tenant')->firstOrFail();
        $second = Listing::publiclyVisible()->whereKeyNot($listing->id)->firstOrFail();

        $this->actingAs($owner)->putJson("/api/v1/owner/listings/{$listing->id}/rental-settings", [
            'minimumStayMonths' => 2, 'maximumStayMonths' => 12, 'minimumNoticeDays' => 4,
            'viewingNoticeHours' => 24, 'viewingWindowStart' => '09:00', 'viewingWindowEnd' => '17:30',
            'utilitiesEstimateLkr' => 4500, 'mealsEstimateLkr' => 14000, 'transportEstimateLkr' => 6000,
            'cancellationPolicy' => 'Cancel a viewing at least twelve hours before the agreed time.',
        ])->assertOk()->assertJsonPath('data.costEstimate.utilities', 4500);

        $from = today()->addDays(10)->toDateString();
        $to = today()->addDays(12)->toDateString();
        $this->postJson("/api/v1/owner/listings/{$listing->id}/availability-blocks", [
            'startDate' => $from, 'endDate' => $to, 'type' => 'maintenance', 'reason' => 'Scheduled plumbing maintenance.',
        ])->assertCreated();
        $this->getJson("/api/v1/listings/{$listing->id}/availability")->assertOk()
            ->assertJsonFragment(['startDate' => $from, 'endDate' => $to, 'type' => 'maintenance']);

        $decision = $this->actingAs($tenant)->postJson('/api/v1/decision-support/compare', [
            'listingIds' => [$listing->id, $second->id], 'maxMonthlyTotalLkr' => 70000,
        ])->assertOk()->assertJsonStructure(['data' => [['listingId', 'rank', 'score', 'monthlyCost', 'reasons']], 'recommendation', 'method']);
        $firstRow = collect($decision->json('data'))->firstWhere('listingId', $listing->id);
        $this->assertSame((int) $listing->monthly_price_lkr + 4500 + 14000 + 6000, $firstRow['monthlyCost']['total']);
        $this->actingAs($owner)->postJson('/api/v1/decision-support/compare', [
            'listingIds' => [$listing->id, $second->id],
        ])->assertForbidden();
    }

    public function test_verification_and_dispute_queues_require_human_admin_decisions(): void
    {
        $tenant = User::where('role', 'tenant')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $evidenceId = $this->actingAs($tenant)->postJson('/api/v1/verification-evidence', [
            'type' => 'phone', 'reference' => 'OTP support reference BB-PHONE-1042',
        ])->assertCreated()->json('data.id');
        $this->actingAs($admin)->getJson('/api/v1/admin/verification-evidence')->assertOk()
            ->assertJsonFragment(['id' => $evidenceId, 'status' => 'pending']);
        $this->putJson("/api/v1/admin/verification-evidence/{$evidenceId}", [
            'status' => 'verified', 'reviewNote' => 'Phone ownership reference was checked by an administrator.',
        ])->assertOk()->assertJsonPath('data.status', 'verified');
        $this->assertNotNull($tenant->fresh()->phone_verified_at);
    }

    public function test_address_normalizer_resolves_local_language_aliases_and_explains_scope(): void
    {
        $this->getJson('/api/v1/address/normalize?q='.urlencode('කටුබැද්ද'))
            ->assertOk()
            ->assertJsonPath('data.area', 'Katubedda')
            ->assertJsonPath('data.city', 'Moratuwa')
            ->assertJsonPath('data.district', 'Colombo')
            ->assertJsonPath('data.confidence', 'high')
            ->assertJsonPath('data.source', 'locality-catalog')
            ->assertJsonStructure(['data' => ['latitude', 'longitude'], 'disclaimer']);
    }

    public function test_price_intelligence_uses_published_peer_statistics_without_claiming_a_valuation(): void
    {
        $listing = Listing::publiclyVisible()->firstOrFail();
        $response = $this->getJson("/api/v1/listings/{$listing->id}/price-intelligence")
            ->assertOk()
            ->assertJsonStructure(['data' => ['available', 'label', 'confidence', 'peerCount', 'method']]);

        if ($response->json('data.available')) {
            $response->assertJsonStructure(['data' => [
                'listingPriceLkr', 'peerMedianLkr', 'peerRangeLkr' => ['low', 'high'],
                'priceVsMedianPercent', 'marketPercentile', 'facilitySignals', 'disclaimer',
            ]]);
            $this->assertStringContainsString('not an official valuation', $response->json('data.disclaimer'));
        }
    }

    public function test_owner_analytics_is_private_and_excludes_tenant_contact_details(): void
    {
        $owner = User::where('role', 'owner')->firstOrFail();
        $tenant = User::where('role', 'tenant')->firstOrFail();

        $this->actingAs($tenant)->getJson('/api/v1/owner/analytics')->assertForbidden();
        $response = $this->actingAs($owner)->getJson('/api/v1/owner/analytics')
            ->assertOk()
            ->assertJsonStructure([
                'summary' => ['listings', 'views', 'favorites', 'enquiries', 'viewings', 'confirmedRentals'],
                'listings' => [['listingId', 'views', 'enquiries', 'viewToEnquiryRate', 'priceIntelligence', 'recommendations']],
                'trend', 'privacy',
            ]);
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $tenant->email, $encoded);
        $this->assertStringNotContainsString((string) $tenant->phone, $encoded);
    }

    public function test_user_can_review_and_revoke_only_their_own_masked_sessions(): void
    {
        $tenant = User::where('email', 'tenant@smartbodim.lk')->firstOrFail();
        $otherTenant = User::where('role', 'tenant')->whereKeyNot($tenant->id)->firstOrFail();
        DB::table('sessions')->insert([
            ['id' => 'tenant-visible-session', 'user_id' => $tenant->id, 'ip_address' => '192.168.10.42', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128.0.0.0', 'payload' => 'fixture', 'last_activity' => now()->timestamp],
            ['id' => 'other-user-private-session', 'user_id' => $otherTenant->id, 'ip_address' => '10.0.0.55', 'user_agent' => 'Private Agent', 'payload' => 'fixture', 'last_activity' => now()->timestamp],
        ]);

        $response = $this->actingAs($tenant)->getJson('/api/v1/account/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device', 'Google Chrome on Windows')
            ->assertJsonPath('data.0.ipAddress', '192.168.*.*');
        $fingerprint = $response->json('data.0.id');
        $this->assertNotSame('tenant-visible-session', $fingerprint);
        $this->assertStringNotContainsString('Private Agent', json_encode($response->json(), JSON_THROW_ON_ERROR));

        $this->deleteJson("/api/v1/account/sessions/{$fingerprint}")->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'tenant-visible-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-private-session']);
    }

    public function test_admin_system_health_is_private_and_exposes_no_credentials(): void
    {
        $tenant = User::where('role', 'tenant')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($tenant)->getJson('/api/v1/admin/system-health')->assertForbidden();
        Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), now()->addMinutes(10));
        $this->mock(AiServiceClient::class, function ($mock): void {
            $mock->shouldReceive('health')->once()->andReturn(['online' => true]);
        });

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/system-health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonStructure(['checkedAt', 'checks' => [['key', 'label', 'status', 'detail']], 'environment', 'fallbackPolicy']);
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('APP_KEY', $encoded);
        $this->assertStringNotContainsString('password', strtolower($encoded));
    }

    public function test_answer_feedback_is_idempotent_explained_and_user_scoped(): void
    {
        $tenant = User::where('email', 'tenant@smartbodim.lk')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $searchId = DB::table('search_logs')->insertGetId([
            'user_id' => $tenant->id,
            'sanitized_query' => 'A room near my campus',
            'filters' => json_encode(['language' => 'en']),
            'result_count' => 3,
            'mode' => 'assistant:semantic',
            'latency_ms' => 18,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($tenant)->postJson('/api/v1/ai/feedback', [
            'event' => 'not_helpful', 'searchLogId' => $searchId, 'issueCategory' => 'wrong_destination',
        ])->assertCreated();
        $this->postJson('/api/v1/ai/feedback', [
            'event' => 'not_helpful', 'searchLogId' => $searchId, 'issueCategory' => 'distance_incorrect',
        ])->assertOk();
        $this->assertSame(1, DB::table('ai_feedback')->where('user_id', $tenant->id)->where('search_log_id', $searchId)->count());
        $this->assertSame('distance_incorrect', data_get(json_decode((string) DB::table('ai_feedback')->where('search_log_id', $searchId)->value('metadata'), true), 'issueCategory'));

        $this->actingAs($admin)->getJson('/api/v1/admin/ai/metrics')->assertOk()
            ->assertJsonFragment(['category' => 'distance_incorrect', 'count' => 1]);
        $this->actingAs($tenant)->postJson('/api/v1/ai/feedback', [
            'event' => 'helpful', 'searchLogId' => $searchId,
        ])->assertCreated();
        $this->assertDatabaseMissing('ai_feedback', ['user_id' => $tenant->id, 'search_log_id' => $searchId, 'event_type' => 'not_helpful']);
        $this->assertDatabaseHas('ai_feedback', ['user_id' => $tenant->id, 'search_log_id' => $searchId, 'event_type' => 'helpful']);
    }

    public function test_listing_risk_review_flags_contact_payment_and_extreme_deposit_signals_without_auto_rejection(): void
    {
        $listing = Listing::firstOrFail();
        $originalStatus = $listing->status;
        $listing->forceFill([
            'description' => 'Contact 077 123 4567 or visit https://example.test. Send money by bank transfer to reserve before viewing this otherwise clearly described room.',
            'deposit_lkr' => (int) $listing->monthly_price_lkr * 7,
        ])->save();

        $assessment = app(ListingRiskService::class)->assess($listing->fresh());
        $codes = collect($assessment->flags)->pluck('code');
        $this->assertContains('off_platform_contact', $codes);
        $this->assertContains('external_contact_link', $codes);
        $this->assertContains('unsafe_payment_instruction', $codes);
        $this->assertContains('extreme_deposit', $codes);
        $this->assertSame(ListingRiskService::VERSION, $assessment->model_version);
        $this->assertSame($originalStatus, $listing->fresh()->status);
        $this->assertStringNotContainsString('077', json_encode($assessment->evidence, JSON_THROW_ON_ERROR));
    }
}
