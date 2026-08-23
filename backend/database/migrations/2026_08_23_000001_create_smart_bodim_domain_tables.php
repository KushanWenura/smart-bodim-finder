<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('district', 80);
            $table->string('city', 80);
            $table->string('area', 100);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->unique(['district', 'city', 'area']);
            $table->index(['city', 'area']);
        });
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('type', 40);
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('active')->default(true);
            $table->index('name');
        });
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 100);
            $table->string('category', 60)->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::create('tenant_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('category', 30)->nullable();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('institution_or_workplace', 180)->nullable();
            $table->foreignId('preferred_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->decimal('preferred_latitude', 10, 7)->nullable();
            $table->decimal('preferred_longitude', 10, 7)->nullable();
            $table->decimal('radius_km', 6, 2)->nullable();
            $table->unsignedInteger('min_budget_lkr')->nullable();
            $table->unsignedInteger('max_budget_lkr')->nullable();
            $table->string('property_type', 50)->nullable();
            $table->string('gender_preference', 30)->nullable();
            $table->unsignedTinyInteger('occupancy_preference')->nullable();
            $table->text('preference_text')->nullable();
            $table->json('required_facilities')->nullable();
            $table->json('preferred_facilities')->nullable();
            $table->timestamps();
        });
        Schema::create('owner_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('business_name', 160)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('verification_reference', 120)->nullable();
            $table->string('verification_status', 30)->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('public_slug', 80)->unique();
            $table->string('title', 160);
            $table->text('description');
            $table->string('property_type', 50);
            $table->unsignedInteger('monthly_price_lkr');
            $table->unsignedInteger('deposit_lkr')->nullable();
            $table->string('pricing_notes', 500)->nullable();
            $table->string('private_address', 300)->nullable();
            $table->string('public_area', 100);
            $table->string('city', 80)->index();
            $table->string('district', 80);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('map_visibility', 20)->default('approximate');
            $table->string('gender_rule', 30)->default('any');
            $table->unsignedTinyInteger('occupancy_limit')->default(1);
            $table->boolean('available')->default(true)->index();
            $table->date('available_from')->nullable();
            $table->boolean('sharing_allowed')->default(false);
            $table->boolean('furnished')->default(false);
            $table->text('house_rules')->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->text('moderation_feedback')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('favorite_count')->default(0);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_indexed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_id', 'status']);
            $table->index(['status', 'available', 'published_at']);
            $table->index(['city', 'public_area', 'monthly_price_lkr']);
        });
        Schema::create('listing_facility', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->primary(['listing_id', 'facility_id']);
        });
        Schema::create('listing_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type', 50);
            $table->unsignedInteger('byte_size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('caption', 240)->nullable();
            $table->string('alt_text', 240);
            $table->unsignedTinyInteger('sort_order');
            $table->boolean('is_cover')->default(false);
            $table->timestamps();
            $table->unique(['listing_id', 'sort_order']);
            $table->index(['listing_id', 'is_cover']);
        });
        Schema::create('listing_nearby_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('name', 180);
            $table->unsignedInteger('distance_m');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->index(['listing_id', 'type']);
        });
        Schema::create('listing_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('previous_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['listing_id', 'created_at']);
        });
        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'listing_id']);
        });
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('natural_query', 500)->nullable();
            $table->json('filters');
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'notifications_enabled']);
        });
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('subject', 160);
            $table->timestamps();
            $table->unique(['listing_id', 'tenant_id', 'owner_id']);
            $table->index(['tenant_id', 'updated_at']);
            $table->index(['owner_id', 'updated_at']);
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->primary(['conversation_id', 'user_id']);
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->string('moderation_status', 30)->default('visible');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'listing_id']);
            $table->index(['listing_id', 'moderation_status']);
        });
        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('status', 30)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['review_id', 'reporter_id']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('review_ai_analyses', function (Blueprint $table) {
            $table->foreignId('review_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('label', 30)->nullable();
            $table->decimal('confidence', 6, 5)->nullable();
            $table->json('aspects')->nullable();
            $table->string('model_version', 120)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('analyzed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
        Schema::create('listing_review_summaries', function (Blueprint $table) {
            $table->foreignId('listing_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('summary_text', 500);
            $table->unsignedInteger('review_count');
            $table->json('aspect_counts');
            $table->string('model_version', 120);
            $table->timestamp('generated_at');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('search_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sanitized_query', 500)->nullable();
            $table->json('filters')->nullable();
            $table->unsignedInteger('result_count');
            $table->string('mode', 30);
            $table->unsignedInteger('latency_ms');
            $table->timestamps();
            $table->index(['created_at', 'mode']);
        });
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('session_key')->nullable();
            $table->string('event_type', 60);
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['event_type', 'occurred_at']);
        });
        Schema::create('ai_model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 30);
            $table->string('version', 120);
            $table->string('base_model');
            $table->string('base_revision', 120)->nullable();
            $table->string('artifact_checksum', 64)->nullable();
            $table->json('manifest');
            $table->boolean('active')->default(false);
            $table->timestamps();
            $table->unique(['purpose', 'version']);
            $table->index(['purpose', 'active']);
        });
        Schema::create('ai_index_records', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_version_id')->constrained('ai_model_versions')->restrictOnDelete();
            $table->string('vector_key', 190)->unique();
            $table->string('content_checksum', 64);
            $table->string('status', 20)->default('pending');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
            $table->primary(['listing_id', 'model_version_id']);
            $table->index(['status', 'updated_at']);
        });
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id');
            $table->text('reason');
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('request_id', 80)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['admin_audit_logs', 'ai_index_records', 'ai_model_versions', 'analytics_events', 'search_logs', 'notifications', 'listing_review_summaries', 'review_ai_analyses', 'review_reports', 'reviews', 'conversation_reads', 'messages', 'conversations', 'saved_searches', 'favorites', 'listing_status_history', 'listing_nearby_places', 'listing_images', 'listing_facility', 'listings', 'owner_profiles', 'tenant_profiles', 'facilities', 'institutions', 'locations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
