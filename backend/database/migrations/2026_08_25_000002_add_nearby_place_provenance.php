<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('listing_nearby_places', 'source_provider')) {
            Schema::table('listing_nearby_places', function (Blueprint $table) {
                $table->string('source_provider', 80)->nullable()->after('longitude');
                $table->string('source_reference', 500)->nullable()->after('source_provider');
                $table->decimal('coordinate_confidence', 4, 3)->nullable()->after('source_reference');
                $table->timestamp('verified_at')->nullable()->after('coordinate_confidence');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('listing_nearby_places', 'source_provider')) {
            Schema::table('listing_nearby_places', function (Blueprint $table) {
                $table->dropColumn([
                    'source_provider',
                    'source_reference',
                    'coordinate_confidence',
                    'verified_at',
                ]);
            });
        }
    }
};
