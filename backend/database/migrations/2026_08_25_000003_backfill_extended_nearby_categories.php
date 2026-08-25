<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE_REFERENCE = 'migration:2026_08_25_extended_nearby_categories';

    public function up(): void
    {
        $definitions = [
            'pharmacy' => ['community pharmacy', 0.0012, -0.0004],
            'bank_atm' => ['bank and ATM', -0.0011, 0.0012],
            'police' => ['Police Station', 0.0015, -0.0010],
            'laundry' => ['laundry service', -0.0014, -0.0005],
        ];

        DB::table('listings')
            ->select(['id', 'public_area', 'city', 'latitude', 'longitude'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function (object $listing) use ($definitions): void {
                $area = trim((string) ($listing->public_area ?: $listing->city ?: 'Local'));

                foreach ($definitions as $type => [$label, $latOffset, $lngOffset]) {
                    $exists = DB::table('listing_nearby_places')
                        ->where('listing_id', $listing->id)
                        ->where('type', $type)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $latitude = (float) $listing->latitude + $latOffset;
                    $longitude = (float) $listing->longitude + $lngOffset;

                    DB::table('listing_nearby_places')->insert([
                        'listing_id' => $listing->id,
                        'type' => $type,
                        'name' => $area.' '.$label,
                        'distance_m' => $this->distanceMetres(
                            (float) $listing->latitude,
                            (float) $listing->longitude,
                            $latitude,
                            $longitude,
                        ),
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'source_provider' => 'project-fixture',
                        'source_reference' => self::SOURCE_REFERENCE,
                        'coordinate_confidence' => 0.40,
                        'verified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('listing_nearby_places')
            ->where('source_reference', self::SOURCE_REFERENCE)
            ->delete();
    }

    private function distanceMetres(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lonDelta / 2) ** 2;

        return (int) round(6371008.8 * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
};
