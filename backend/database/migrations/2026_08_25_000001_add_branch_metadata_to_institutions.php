<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('organization_name', 180)->nullable()->after('name');
            $table->string('branch_name', 100)->nullable()->after('organization_name');
            $table->json('aliases')->nullable()->after('branch_name');
            $table->string('source_url', 500)->nullable()->after('aliases');
            $table->index(['organization_name', 'branch_name'], 'institutions_organization_branch_index');
        });

        DB::table('institutions')->whereNull('organization_name')->update(['organization_name' => DB::raw('name')]);

        $source = 'https://icbt.lk/branches/';
        $branches = [
            ['Colombo', 6.8859, 79.8573, ['icbt', 'icbt campus', 'icbt colombo', 'icbt bambalapitiya']],
            ['Kandy', 7.2963, 80.6350, ['icbt', 'icbt campus', 'icbt kandy']],
            ['Galle', 6.0375, 80.2160, ['icbt', 'icbt campus', 'icbt galle']],
            ['Nugegoda', 6.8721, 79.8899, ['icbt', 'icbt campus', 'icbt nugegoda']],
            ['Batticaloa', 7.7102, 81.7026, ['icbt', 'icbt campus', 'icbt batticaloa']],
            ['Matara', 5.9485, 80.5350, ['icbt', 'icbt campus', 'icbt matara', 'icbt southern campus']],
            ['Jaffna', 9.6667, 80.0250, ['icbt', 'icbt campus', 'icbt jaffna']],
            ['Kurunegala', 7.4863, 80.3652, ['icbt', 'icbt campus', 'icbt kurunegala']],
            ['Gampaha', 7.0912, 79.9983, ['icbt', 'icbt campus', 'icbt gampaha']],
            ['Anuradhapura', 8.3350, 80.4108, ['icbt', 'icbt campus', 'icbt anuradhapura']],
        ];
        foreach ($branches as [$branch, $latitude, $longitude, $aliases]) {
            DB::table('institutions')->updateOrInsert(['name' => "ICBT Campus - {$branch}"], [
                'type' => 'campus',
                'organization_name' => 'ICBT Campus',
                'branch_name' => $branch,
                'aliases' => json_encode($aliases),
                'source_url' => $source,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'active' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropIndex('institutions_organization_branch_index');
            $table->dropColumn(['organization_name', 'branch_name', 'aliases', 'source_url']);
        });
    }
};
