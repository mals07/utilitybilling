<?php

namespace App\Support;

use App\Enums\Access;
use App\Enums\ChargeValueType;
use App\Enums\DistributionType;
use App\Enums\FeeBilling;
use App\Enums\Page;
use App\Enums\WorkOrderType;
use App\Models\ChargeType;
use App\Models\Company;
use App\Models\RateType;
use Illuminate\Support\Facades\DB;

/**
 * The example records the setup wizard's "Use example" buttons add, matching the examples shown
 * beside each step. Each is added only when the company has nothing of that name yet, so pressing
 * a button twice changes nothing.
 */
final class SetupExamples
{
    public static function lists(Company $company): void
    {
        $lists = [
            'accountTypes' => ['Residential', 'Commercial', 'Government'],
            'meterTypes' => ['1/2" Brass', '3/4" Brass'],
            'clusterTypes' => ['Zone 1', 'Zone 2'],
            'departments' => ['Billing', 'Field Operations'],
        ];

        foreach ($lists as $relation => $names) {
            foreach ($names as $name) {
                $company->{$relation}()->withTrashed()->firstOrCreate(['name' => $name]);
            }
        }
    }

    public static function rates(Company $company): void
    {
        if ($company->rateTypes()->where('name', 'Residential')->exists()) {
            return;
        }

        DB::transaction(function () use ($company): void {
            /** @var RateType $rate */
            $rate = $company->rateTypes()->create(['name' => 'Residential', 'description' => 'Households']);
            $rate->ranges()->createMany([
                ['range_from' => 0, 'range_to' => 10, 'rate' => '10.00', 'description' => '0-10 cu.m.'],
                ['range_from' => 11, 'range_to' => 20, 'rate' => '25.00', 'description' => '11-20 cu.m.'],
                ['range_from' => 21, 'range_to' => 99999, 'rate' => '35.00', 'description' => 'Above 20 cu.m.'],
            ]);
            $rate->setup()->create([
                'minimum_consumption' => 10,
                'defective_uses_minimum_consumption' => true,
                'defective_uses_three_month_average' => false,
            ]);
        });
    }

    public static function charges(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            $surcharge = $company->chargeElements()->firstOrCreate(
                ['name' => 'Late Payment Surcharge'],
                ['distribution_type' => DistributionType::Credit, 'is_after_due' => true, 'is_taxable' => false],
            );

            /** @var ChargeType $standard */
            $standard = $company->chargeTypes()->firstOrCreate(['name' => 'Standard'], ['description' => 'Charges for most customers']);
            $standard->elements()->firstOrCreate(
                ['charge_element_id' => $surcharge->id],
                ['value_type' => ChargeValueType::Percentage, 'value' => '2.00'],
            );

            $company->workOrderFees()->firstOrCreate(
                ['order_type' => WorkOrderType::NewInstallation],
                ['amount' => '1500.00', 'billing' => FeeBilling::SeparateInvoice],
            );
        });
    }

    public static function roles(Company $company): void
    {
        $roles = [
            'Meter Reader' => [
                'description' => 'Reads meters in the field',
                'pages' => [Page::MeterReadings->value => [Access::View, Access::Add], Page::Customers->value => [Access::View]],
            ],
            'Cashier' => [
                'description' => 'Posts payments at the office',
                'pages' => [Page::Payments->value => [Access::View, Access::Add], Page::Invoices->value => [Access::View], Page::Customers->value => [Access::View]],
            ],
        ];

        DB::transaction(function () use ($company, $roles): void {
            foreach ($roles as $name => $role) {
                if ($company->roles()->where('name', $name)->exists()) {
                    continue;
                }

                $created = $company->roles()->create(['name' => $name, 'description' => $role['description']]);
                foreach ($role['pages'] as $page => $access) {
                    $flags = [];
                    foreach (Access::cases() as $type) {
                        $flags[$type->column()] = in_array($type, $access, true);
                    }
                    $created->permissions()->create(['page' => $page, ...$flags]);
                }
            }
        });
    }
}
