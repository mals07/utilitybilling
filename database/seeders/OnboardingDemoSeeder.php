<?php

namespace Database\Seeders;

use App\Enums\Access;
use App\Enums\ChargeValueType;
use App\Enums\DistributionType;
use App\Enums\Page;
use App\Enums\ReadingCode;
use App\Enums\ReadingKind;
use App\Enums\WorkOrderType;
use App\Models\AccountType;
use App\Models\ChargeElement;
use App\Models\ChargeType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MeterType;
use App\Models\RateType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReading;
use App\Support\Billing\BillingRun;
use App\Support\Billing\Ledger;
use App\Support\Billing\MeterReadings;
use Illuminate\Database\Seeder;

/**
 * A demo company with a few months of billing, used for the onboarding tour screenshots.
 *
 * Run with: php artisan db:seed --class=OnboardingDemoSeeder
 * Sign in as the admin below; delete the company's users and data when no longer needed.
 */
class OnboardingDemoSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@demo-water.test';

    public const ADMIN_PASSWORD = 'Demo-979eba895f3b';

    private const CUSTOMERS = [
        ['Maria', 'Santos', 'Residential', 'Zone 1'],
        ['Jose', 'Reyes', 'Residential', 'Zone 1'],
        ['Ana', 'Cruz', 'Residential', 'Zone 2'],
        ['Pedro', 'Bautista', 'Commercial', 'Zone 2'],
        ['Liza', 'Garcia', 'Residential', 'Zone 1'],
        ['Ramon', 'Mendoza', 'Residential', 'Zone 2'],
        ['Carmen', 'Villanueva', 'Commercial', 'Zone 1'],
        ['Andres', 'Ramos', 'Residential', 'Zone 2'],
    ];

    public function run(): void
    {
        if (User::query()->where('email', self::ADMIN_EMAIL)->exists()) {
            $this->command?->info('The demo company already exists.');

            return;
        }

        $owner = User::factory()->create([
            'name' => 'Demo Admin',
            'first_name' => 'Demo',
            'last_name' => 'Admin',
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
            'tour_completed_at' => now(),
        ]);

        $company = Company::factory()->create([
            'user_id' => $owner->id,
            'company_name' => 'Demo Water District',
            'email' => 'office@demo-water.test',
            'point_person_first_name' => 'Demo',
            'point_person_last_name' => 'Admin',
            'point_person_email' => self::ADMIN_EMAIL,
            'currency' => 'PHP',
            'due_date_days' => 15,
            'billing_header_1' => 'Demo Water District',
            'billing_footer_1' => 'Please pay on or before the due date to avoid surcharges.',
        ]);
        $owner->refresh();

        $accountTypes = [
            'Residential' => AccountType::factory()->for($company)->create(['name' => 'Residential']),
            'Commercial' => AccountType::factory()->for($company)->create(['name' => 'Commercial']),
        ];
        $meterType = MeterType::factory()->for($company)->create(['name' => '1/2" Brass Meter']);
        $clusters = [
            'Zone 1' => ClusterType::factory()->for($company)->create(['name' => 'Zone 1']),
            'Zone 2' => ClusterType::factory()->for($company)->create(['name' => 'Zone 2']),
        ];

        $rates = [
            'Residential' => $this->rate($company, 'Residential', ['10.00', '25.00', '35.00']),
            'Commercial' => $this->rate($company, 'Commercial', ['20.00', '40.00', '55.00']),
        ];

        $chargeType = ChargeType::factory()->for($company)->create(['name' => 'Standard']);
        $surcharge = ChargeElement::factory()->for($company)->create([
            'name' => 'Late Payment Surcharge', 'distribution_type' => DistributionType::Credit, 'is_after_due' => true,
        ]);
        $chargeType->elements()->create(['charge_element_id' => $surcharge->id, 'value_type' => ChargeValueType::Percentage, 'value' => '2.00']);

        $readerRole = Role::factory()->for($company)->create(['name' => 'Meter Reader']);
        $readerRole->permissions()->create(['page' => Page::MeterReadings->value, ...$this->flags([Access::View, Access::Add])]);
        $readerRole->permissions()->create(['page' => Page::Customers->value, ...$this->flags([Access::View])]);
        User::factory()->memberOf($company, $readerRole)->create(['name' => 'Ben Torres', 'first_name' => 'Ben', 'last_name' => 'Torres', 'email' => 'ben@demo-water.test', 'tour_completed_at' => now()]);

        $twoMonthsAgo = now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(4);
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(4);
        $thisPeriod = now()->format('Y-m');

        foreach (self::CUSTOMERS as $index => [$firstName, $lastName, $account, $cluster]) {
            $customer = Customer::factory()->for($company)->connected()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'account_type_id' => $accountTypes[$account]->id,
                'rate_type_id' => $rates[$account]->id,
                'charge_type_id' => $chargeType->id,
                'cluster_type_id' => $clusters[$cluster]->id,
                'meter_type_id' => $meterType->id,
            ]);

            $installation = WorkOrder::factory()->forCustomer($customer, WorkOrderType::NewInstallation)->completed()->create([
                'requested_at' => now()->subMonths(4), 'completed_at' => now()->subMonths(4),
            ]);
            WorkOrderReading::query()->create([
                'company_id' => $company->id, 'customer_id' => $customer->id, 'work_order_id' => $installation->id,
                'meter_number' => $customer->meter_number, 'kind' => ReadingKind::Initial, 'reading' => '0',
                'read_on' => now()->subMonths(4)->toDateString(),
            ]);

            $first = 12 + $index * 2;
            $second = $first + 14 + $index;
            $olderBill = BillingRun::generate($customer, $twoMonthsAgo->format('Y-m'), (string) $first, false, $twoMonthsAgo->toDateString(), $twoMonthsAgo->toDateString(), $owner);
            BillingRun::generate($customer, $lastMonth->format('Y-m'), (string) $second, false, $lastMonth->toDateString(), $lastMonth->toDateString(), $owner);

            // Most customers paid their older bill; the last two did not, so they show up on Disconnections.
            if ($index < 6) {
                Ledger::recordPayment($customer, [
                    'paid_on' => $twoMonthsAgo->copy()->addDays(10)->toDateString(),
                    'amount' => $olderBill->total_amount,
                    'method' => 'cash',
                    'reference_number' => null,
                    'notes' => null,
                ], $owner);
            }

            // This month's readings are half done, waiting to be billed.
            if ($index % 2 === 0) {
                MeterReadings::save($customer, $thisPeriod, (string) ($second + 15), ReadingCode::Normal, now()->toDateString(), $owner);
            }
        }

        foreach ([['Rosa', 'Aquino'], ['Miguel', 'Castillo']] as [$firstName, $lastName]) {
            $pending = Customer::factory()->for($company)->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'account_type_id' => $accountTypes['Residential']->id,
                'rate_type_id' => $rates['Residential']->id,
                'charge_type_id' => $chargeType->id,
                'cluster_type_id' => $clusters['Zone 1']->id,
            ]);
            WorkOrder::factory()->forCustomer($pending, WorkOrderType::NewInstallation)->create([
                'description' => 'Install a new 1/2" meter at the front gate.',
            ]);
        }

        $this->command?->info('Demo company created. Sign in as '.self::ADMIN_EMAIL.' (password in OnboardingDemoSeeder::ADMIN_PASSWORD).');
    }

    /**
     * A rate type with three consumption tiers and a 10 cu.m. minimum.
     *
     * @param  array{0: string, 1: string, 2: string}  $tierRates
     */
    private function rate(Company $company, string $name, array $tierRates): RateType
    {
        $rate = RateType::factory()->for($company)->create(['name' => $name, 'description' => "{$name} water rate"]);
        $rate->ranges()->createMany([
            ['range_from' => 0, 'range_to' => 10, 'rate' => $tierRates[0], 'description' => '0-10 cu.m.'],
            ['range_from' => 11, 'range_to' => 20, 'rate' => $tierRates[1], 'description' => '11-20 cu.m.'],
            ['range_from' => 21, 'range_to' => 99999, 'rate' => $tierRates[2], 'description' => 'Above 20 cu.m.'],
        ]);
        $rate->setup()->create([
            'minimum_consumption' => 10,
            'defective_uses_minimum_consumption' => true,
            'defective_uses_three_month_average' => true,
        ]);

        return $rate;
    }

    /**
     * Role permission columns granting only the given access.
     *
     * @param  list<Access>  $access
     * @return array<string, bool>
     */
    private function flags(array $access): array
    {
        $flags = [];
        foreach (Access::cases() as $type) {
            $flags[$type->column()] = in_array($type, $access, true);
        }

        return $flags;
    }
}
