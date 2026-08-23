<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('tenant_archived_at')->nullable()->after('subject');
            $table->timestamp('owner_archived_at')->nullable()->after('tenant_archived_at');
        });
        Schema::create('listing_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->unique(['listing_id', 'reporter_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_reports');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['tenant_archived_at', 'owner_archived_at']);
        });
    }
};
