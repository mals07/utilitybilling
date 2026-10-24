<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\Page;
use App\Enums\PaymentMethod;
use App\Enums\ReadingCode;
use App\Enums\WorkOrderStatus;
use App\Livewire\Dashboard;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, October 19, 2026 at noon in Manila.
        $this->travelTo(Carbon::parse('2026-10-19 12:00', 'Asia/Manila'));
    }

    private function bill(Customer $customer, string $date, string $amount, array $attributes = []): Invoice
    {
        return Invoice::createNumbered($customer->company, [
            'customer_id' => $customer->id,
            'kind' => InvoiceKind::Monthly,
            'billing_period' => substr($date, 0, 7),
            'invoice_date' => $date,
            'due_date' => $date,
            'total_amount' => $amount,
            'balance' => $amount,
            'status' => InvoiceStatus::Unpaid,
            ...$attributes,
        ]);
    }

    private function payment(Customer $customer, string $paidOn, string $amount, array $attributes = []): Payment
    {
        return Payment::createNumbered($customer->company, [
            'customer_id' => $customer->id,
            'paid_on' => $paidOn,
            'amount' => $amount,
            'unapplied_amount' => '0.00',
            'method' => PaymentMethod::Cash,
            ...$attributes,
        ]);
    }

    private function customerOf(Company $company): Customer
    {
        return Customer::factory()->for($company)->connected()->create();
    }

    public function test_headline_figures_count_only_the_months_bills_and_payments_that_stand(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = $this->customerOf($company);
        $this->bill($customer, '2026-10-05', '1000.00');
        $this->bill($customer, '2026-10-06', '999.00', ['status' => InvoiceStatus::Void, 'voided_at' => now()]);
        $this->bill($customer, '2026-09-05', '500.00');
        $this->bill($this->customerOf(Company::factory()->create()), '2026-10-05', '777.00');
        $this->payment($customer, '2026-10-10', '600.00');
        $this->payment($customer, '2026-10-11', '50.00', ['voided_at' => now()]);
        $this->payment($customer, '2026-09-10', '500.00');

        $component = Livewire::actingAs($owner)->test(Dashboard::class);

        $component->assertViewHas('revenue', [
            'billed' => 100000,
            'collected' => 60000,
            'efficiency' => 60.0,
            'previousBilled' => 50000,
            'previousCollected' => 50000,
            'previousEfficiency' => 100.0,
        ]);
    }

    public function test_aging_groups_unpaid_balances_by_days_past_due(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = $this->customerOf($company);
        $this->bill($customer, '2026-10-19', '100.00');
        $this->bill($customer, '2026-09-19', '200.00');
        $this->bill($customer, '2026-09-18', '300.00');
        $this->bill($customer, '2026-07-20', '400.00');
        $this->bill($customer, '2026-07-01', '900.00', ['status' => InvoiceStatus::Paid, 'balance' => '0.00']);

        $component = Livewire::actingAs($owner)->test(Dashboard::class);

        $component->assertViewHas('aging', [
            ['label' => 'Not yet due', 'cents' => 10000],
            ['label' => '1–30 days', 'cents' => 20000],
            ['label' => '31–60 days', 'cents' => 30000],
            ['label' => '61–90 days', 'cents' => 0],
            ['label' => 'Over 90 days', 'cents' => 40000],
        ]);
    }

    public function test_delinquent_accounts_are_ranked_by_the_amount_past_due(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $small = $this->customerOf($company);
        $large = $this->customerOf($company);
        $this->bill($small, '2026-09-01', '300.00');
        $this->bill($small, '2026-10-30', '5000.00');
        $this->bill($large, '2026-08-01', '400.00');
        $this->bill($large, '2026-09-01', '400.00');

        $component = Livewire::actingAs($owner)->test(Dashboard::class);

        $component->assertViewHas('topDelinquent', fn (array $accounts): bool => array_column($accounts, 'customerId') === [$large->id, $small->id]
            && array_column($accounts, 'cents') === [80000, 30000]
            && array_column($accounts, 'bills') === [2, 1]);
    }

    public function test_members_see_only_the_sections_of_pages_they_can_open(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::WorkOrders, [Access::View]);

        $response = $this->actingAs($member)->get(route('dashboard'));

        $response->assertSee('Open orders by type');
        $response->assertDontSee('Receivables aging');
        $response->assertDontSee('Collections by payment method');
        $response->assertDontSee('Meter reading progress');
    }

    public function test_members_without_access_to_any_source_page_see_a_notice(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::Rates, [Access::View]);

        $response = $this->actingAs($member)->get(route('dashboard'));

        $response->assertSee('No performance figures to show');
        $response->assertDontSee('6-month performance');
    }

    #[TestWith(['2026-11'], 'future month')]
    #[TestWith(['2026-13'], 'not a month')]
    #[TestWith(['october'], 'not a date')]
    public function test_a_month_that_cannot_be_shown_falls_back_to_the_current_month(string $month): void
    {
        [$owner] = $this->ownerWithCompany();

        $component = Livewire::actingAs($owner)->withQueryParams(['month' => $month])->test(Dashboard::class);

        $component->assertViewHas('shownMonth', '2026-10');
    }

    public function test_the_trend_covers_the_six_months_ending_with_the_chosen_month(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = $this->customerOf($company);
        $this->bill($customer, '2026-02-27', '900.00');
        $this->bill($customer, '2026-03-02', '100.00');
        $this->bill($customer, '2026-08-31', '250.00');
        $this->payment($customer, '2026-03-15', '50.00');

        $component = Livewire::actingAs($owner)->test(Dashboard::class)->set('month', '2026-08');

        $component->assertViewHas('trendMonths', ['Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026']);
        $component->assertViewHas('trendMeasures', function (array $measures): bool {
            $revenue = collect($measures)->firstWhere('key', 'revenue');
            $efficiency = collect($measures)->firstWhere('key', 'efficiency');

            return $revenue['series'][0]['values'] === [100.0, 0.0, 0.0, 0.0, 0.0, 250.0]
                && $revenue['series'][1]['values'] === [50.0, 0.0, 0.0, 0.0, 0.0, 0.0]
                && $efficiency['series'][0]['values'] === [50.0, null, null, null, null, 0.0];
        });
    }

    public function test_the_trend_offers_only_measures_from_pages_the_member_can_open(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::Invoices, [Access::View]);

        $component = Livewire::actingAs($member)->test(Dashboard::class);

        $component->assertViewHas('trendMeasures', fn (array $measures): bool => array_column($measures, 'label') === ['Billed']);
    }

    public function test_work_orders_count_overdue_orders_and_average_completion_time(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        WorkOrder::factory()->for($company)->requestedDaysAgo(7)->create();
        WorkOrder::factory()->for($company)->requestedDaysAgo(2)->create();
        WorkOrder::factory()->for($company)->onHold()->create();
        WorkOrder::factory()->for($company)->create([
            'status' => WorkOrderStatus::Completed,
            'requested_at' => Carbon::parse('2026-10-01 08:00', 'Asia/Manila')->utc(),
            'completed_at' => Carbon::parse('2026-10-04 08:00', 'Asia/Manila')->utc(),
        ]);
        WorkOrder::factory()->for($company)->create([
            'status' => WorkOrderStatus::Completed,
            'requested_at' => Carbon::parse('2026-10-10 08:00', 'Asia/Manila')->utc(),
            'completed_at' => Carbon::parse('2026-10-11 08:00', 'Asia/Manila')->utc(),
        ]);
        WorkOrder::factory()->for($company)->create([
            'status' => WorkOrderStatus::Completed,
            'requested_at' => Carbon::parse('2026-09-01 08:00', 'Asia/Manila')->utc(),
            'completed_at' => Carbon::parse('2026-09-30 23:00', 'Asia/Manila')->utc(),
        ]);

        $component = Livewire::actingAs($owner)->test(Dashboard::class);

        $component->assertViewHas('workOrders', fn (array $orders): bool => $orders['inProgress'] === 2
            && $orders['onHold'] === 1
            && $orders['overdue'] === 1
            && $orders['completed'] === 2
            && $orders['averageDays'] === 2.0);
    }

    public function test_reading_progress_counts_connected_meters_not_yet_read(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customers = Customer::factory()->for($company)->connected()->count(3)->create();
        foreach ([[MeterReadingStatus::Ready, ReadingCode::Normal], [MeterReadingStatus::Review, ReadingCode::NoAccess]] as $index => [$status, $code]) {
            MeterReading::query()->create([
                'company_id' => $company->id,
                'customer_id' => $customers[$index]->id,
                'meter_number' => $customers[$index]->meter_number,
                'billing_period' => '2026-10',
                'status' => $status,
                'reading_code' => $code,
                'previous_reading' => '100',
                'present_reading' => '112',
                'consumption' => '12',
                'read_on' => '2026-10-15',
            ]);
        }

        $component = Livewire::actingAs($owner)->test(Dashboard::class);

        $component->assertViewHas('readingProgress', fn (array $progress): bool => $progress['expected'] === 3
            && $progress['notRead'] === 1
            && $progress['ready'] === 1
            && $progress['review'] === 1
            && $progress['noAccess'] === 1);
        $component->assertViewHas('water', fn (array $water): bool => $water['consumption'] === 12.0 && $water['readings'] === 1);
    }
}
