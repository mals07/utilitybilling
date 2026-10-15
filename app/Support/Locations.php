<?php

namespace App\Support;

use App\Models\City;
use App\Models\Country;
use App\Models\Province;

/**
 * Country, province and city choices for the address dropdowns.
 */
final class Locations
{
    /**
     * @return array{
     *     countries: list<array{id: int, name: string}>,
     *     provinces: list<array{id: int, name: string, countryId: int}>,
     *     cities: list<array{id: int, name: string, provinceId: int}>,
     * }
     */
    public static function all(): array
    {
        return [
            'countries' => Country::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Country $country): array => ['id' => $country->id, 'name' => $country->name])->all(),
            'provinces' => Province::query()->orderBy('name')->get(['id', 'name', 'country_id'])
                ->map(fn (Province $province): array => [
                    'id' => $province->id, 'name' => $province->name, 'countryId' => $province->country_id,
                ])->all(),
            'cities' => City::query()->orderBy('name')->get(['id', 'name', 'province_id'])
                ->map(fn (City $city): array => [
                    'id' => $city->id, 'name' => $city->name, 'provinceId' => $city->province_id,
                ])->all(),
        ];
    }

    /**
     * Confirms the city, province and country exist and belong together. Returns [field suffix => message].
     *
     * @return array<string, string> Keyed by "CityId" or "ProvinceId".
     */
    public static function check(int $cityId, int $provinceId, int $countryId, string $cityMessage): array
    {
        $city = City::query()->with('province:id,country_id')->find($cityId, ['id', 'province_id']);

        if ($city === null) {
            return ['CityId' => $cityMessage];
        }

        if ($city->province_id !== $provinceId) {
            return ['CityId' => 'The selected city is not in the selected state/province.'];
        }

        if ($city->province->country_id !== $countryId) {
            return ['ProvinceId' => 'The selected state/province is not in the selected country/region.'];
        }

        return [];
    }
}
