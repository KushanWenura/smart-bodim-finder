<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_safety_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();
            $table->string('visit_basis', 30);
            $table->string('visit_period', 20);
            $table->date('visited_on')->nullable();
            $table->unsignedTinyInteger('lighting_rating');
            $table->unsignedTinyInteger('transport_rating');
            $table->unsignedTinyInteger('public_activity_rating');
            $table->unsignedTinyInteger('road_safety_rating');
            $table->unsignedTinyInteger('emergency_access_rating');
            $table->text('comment');
            $table->boolean('consent_for_research')->default(false);
            $table->string('moderation_status', 20)->default('pending');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['listing_id', 'tenant_id']);
            $table->index(['listing_id', 'moderation_status']);
            $table->index(['consent_for_research', 'moderation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_safety_reports');
    }
};
