<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->json('learned_preferences')->nullable()->after('preference_text');
            $table->boolean('ai_learning_enabled')->default(true)->after('learned_preferences');
        });

        Schema::table('search_logs', function (Blueprint $table) {
            $table->uuid('session_key')->nullable()->after('user_id')->index();
            $table->json('intent_confidence')->nullable()->after('filters');
            $table->string('model_version', 120)->nullable()->after('mode');
            $table->string('experiment_bucket', 40)->default('ranking-v2')->after('model_version');
        });

        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_log_id')->nullable()->constrained('search_logs')->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 40);
            $table->unsignedSmallInteger('position')->nullable();
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['user_id', 'event_type', 'occurred_at']);
            $table->index(['listing_id', 'event_type']);
        });

        Schema::create('listing_ai_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('flags');
            $table->json('evidence')->nullable();
            $table->string('model_version', 100);
            $table->string('status', 20)->default('complete');
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->index(['risk_score', 'assessed_at']);
        });

        Schema::create('ai_evaluation_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('search_log_id')->nullable()->constrained('search_logs')->nullOnDelete();
            $table->string('language', 20)->default('unknown');
            $table->string('query_hash', 64)->index();
            $table->text('anonymized_query');
            $table->json('predicted_intent');
            $table->json('candidate_listing_ids')->nullable();
            $table->json('human_labels')->nullable();
            $table->string('annotation_status', 20)->default('pending');
            $table->boolean('consent_confirmed')->default(false);
            $table->timestamps();
            $table->index(['annotation_status', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_samples');
        Schema::dropIfExists('listing_ai_risk_assessments');
        Schema::dropIfExists('ai_feedback');

        Schema::table('search_logs', function (Blueprint $table) {
            $table->dropColumn(['session_key', 'intent_confidence', 'model_version', 'experiment_bucket']);
        });
        Schema::table('tenant_profiles', function (Blueprint $table) {
            $table->dropColumn(['learned_preferences', 'ai_learning_enabled']);
        });
    }
};
