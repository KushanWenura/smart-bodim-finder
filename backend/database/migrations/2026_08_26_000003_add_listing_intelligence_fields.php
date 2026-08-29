<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_images', function (Blueprint $table) {
            $table->unsignedTinyInteger('quality_score')->nullable()->after('height');
            $table->json('quality_flags')->nullable()->after('quality_score');
            $table->string('perceptual_hash', 16)->nullable()->after('quality_flags')->index();
            $table->timestamp('analyzed_at')->nullable()->after('perceptual_hash');
        });
    }

    public function down(): void
    {
        Schema::table('listing_images', function (Blueprint $table) {
            $table->dropIndex(['perceptual_hash']);
            $table->dropColumn(['quality_score', 'quality_flags', 'perceptual_hash', 'analyzed_at']);
        });
    }
};
