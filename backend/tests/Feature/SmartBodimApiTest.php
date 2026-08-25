<?php

namespace Tests\Feature;

use App\Jobs\SynchronizeListingIndex;
use App\Models\Listing;
use App\Models\User;
use App\Services\AiServiceClient;
use App\Services\ListingRiskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            ->assertJsonPath('meta.distanceMethod', 'Haversine straight-line distance');

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
        Http::fake(fn () => Http::failedConnection());
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
        Http::fake(fn () => Http::failedConnection());
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
}
