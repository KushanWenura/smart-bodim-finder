<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('preferred_locale', 5)->default('en')->after('phone_verified_at');
            $table->boolean('notification_email_enabled')->default(true)->after('preferred_locale');
        });

        Schema::create('listing_rental_settings', function (Blueprint $table) {
            $table->foreignId('listing_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('minimum_stay_months')->default(1);
            $table->unsignedTinyInteger('maximum_stay_months')->nullable();
            $table->unsignedTinyInteger('minimum_notice_days')->default(2);
            $table->unsignedTinyInteger('viewing_notice_hours')->default(12);
            $table->time('viewing_window_start')->default('09:00');
            $table->time('viewing_window_end')->default('18:00');
            $table->unsignedInteger('utilities_estimate_lkr')->default(3500);
            $table->unsignedInteger('meals_estimate_lkr')->default(12000);
            $table->unsignedInteger('transport_estimate_lkr')->default(5000);
            $table->string('cancellation_policy', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('listing_availability_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('type', 30)->default('owner_block');
            $table->string('reason', 300)->nullable();
            $table->timestamps();
            $table->index(['listing_id', 'start_date', 'end_date'], 'listing_block_range_index');
        });

        Schema::table('viewing_requests', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('tenant_checked_in_at')->nullable();
            $table->timestamp('owner_checked_in_at')->nullable();
            $table->timestamp('tenant_checked_out_at')->nullable();
            $table->timestamp('owner_checked_out_at')->nullable();
            $table->string('tenant_attendance', 30)->nullable();
            $table->string('owner_attendance', 30)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->string('share_token_hash', 64)->nullable()->unique();
        });

        Schema::create('rental_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('agreement_number', 40)->unique();
            $table->string('terms_version', 30)->default('2026-01');
            $table->json('terms_snapshot');
            $table->string('content_hash', 64);
            $table->string('status', 25)->default('pending_acceptance');
            $table->timestamp('tenant_accepted_at')->nullable();
            $table->timestamp('owner_accepted_at')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('rental_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 40);
            $table->text('details');
            $table->string('status', 30)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->unique(['reservation_id', 'reporter_id', 'category'], 'reservation_reporter_category_unique');
        });

        Schema::create('verification_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('reference', 160);
            $table->string('status', 30)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_evidence');
        Schema::dropIfExists('rental_disputes');
        Schema::dropIfExists('rental_agreements');
        Schema::table('viewing_requests', function (Blueprint $table) {
            $table->dropColumn([
                'reminder_sent_at', 'tenant_checked_in_at', 'owner_checked_in_at', 'tenant_checked_out_at',
                'owner_checked_out_at', 'tenant_attendance', 'owner_attendance', 'emergency_contact_name',
                'emergency_contact_phone', 'share_token_hash',
            ]);
        });
        Schema::dropIfExists('listing_availability_blocks');
        Schema::dropIfExists('listing_rental_settings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_verified_at', 'preferred_locale', 'notification_email_enabled']);
        });
    }
};
