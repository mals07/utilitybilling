<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\Province;
use Illuminate\Database\Seeder;

/**
 * The Philippine provinces and cities used by the address dropdowns. Safe to run again.
 */
class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $philippines = Country::query()->updateOrCreate(['code' => 'PH'], ['name' => 'Philippines']);

        /** @var array<string, list<string>> $locations */
        $locations = require __DIR__.'/data/ph-locations.php';

        foreach ($locations as $provinceName => $cityNames) {
            $province = Province::query()->firstOrCreate(['country_id' => $philippines->id, 'name' => $provinceName]);

            City::query()->insertOrIgnore(
                array_map(fn (string $name): array => ['name' => $name, 'province_id' => $province->id], $cityNames),
            );
        }

        $this->command?->info(sprintf('Seeded %d provinces and %d cities.', Province::count(), City::count()));
    }
}
