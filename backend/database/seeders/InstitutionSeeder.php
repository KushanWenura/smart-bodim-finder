<?php

namespace Database\Seeders;

use App\Models\Institution;
use Illuminate\Database\Seeder;
use RuntimeException;

class InstitutionSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('../datasets/catalog/sri_lanka_higher_education_destinations.json');
        if (! is_file($path)) {
            throw new RuntimeException("Institution catalog not found: {$path}");
        }

        $catalog = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        Institution::query()->update(['active' => false]);
        foreach ($catalog['destinations'] as $destination) {
            Institution::query()->updateOrCreate(['name' => $destination['name']], [
                'type' => $destination['type'],
                'organization_name' => $destination['organization'],
                'branch_name' => $destination['branch'],
                'aliases' => $destination['aliases'],
                'source_url' => $destination['sourceUrl'],
                'latitude' => $destination['latitude'],
                'longitude' => $destination['longitude'],
                'active' => true,
            ]);
        }

        $this->command?->info(count($catalog['destinations']).' campus, branch and workplace destinations synchronized.');
    }
}
