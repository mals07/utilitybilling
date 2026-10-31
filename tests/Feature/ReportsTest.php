<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentType;
use App\Enums\DisconnectionNoticeStatus;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceLineKind;
use App\Enums\InvoiceStatus;
use App\Enums\MeterReadingStatus;
use App\Enums\Page;
use App\Enums\PaymentMethod;
use App\Enums\ReadingCode;
use App\Enums\WorkOrderType;
use App\Models\ClusterType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DisconnectionNotice;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\CurrentCompany;
use App\Support\Reports\ReportFilters;
use App\Support\Reports\Reports;
use App\Support\Reports\ReportTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, October 19, 2026 at noon in Manila.
        $this->travelTo(Carbon::parse('2026-10-19 12:00', 'Asia/Manila'));
    }

    /**
     * @return array<string, array{0: Page}>
     */
    public static function reportPages(): array
    {
        return collect(Page::reports())->mapWithKeys(fn (Page $page): array => [$page->value => [$page]])->all();
    }

    /**
     * The report's headline figures (label => value) and tables (title => table), worked out as the company.
     *
     * @param  array<string, string>  $input
     * @return array{summary: array<string, string>, tables: array<string, ReportTable>, filters: ReportFilters}
     */
    private function runReport(Page $page, Company $company, array $input = []): array
    {
        return CurrentCompany::as($company, function () use ($page, $company, $input): array {
            $report = Reports::make($page, $company, $input);

            return [
                'summary' => array_column($report->summary(), 'value', 'label'),
                'tables' => collect($report->tables())->keyBy('title')->all(),
                'filters' => $report->appliedFilters(),
            ];
        });
    }

    private function customerIn(Company $company, ?ClusterType $zone = null, array $attributes = []): Customer
    {
        return Customer::factory()->for($company)->connected()->create([
            ...($zone === null ? [] : ['cluster_type_id' => $zone->id]),
            ...$attributes,
        ]);
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

    private function reading(Customer $customer, string $period, string $consumption, MeterReadingStatus $status = MeterReadingStatus::Ready, ReadingCode $code = ReadingCode::Normal): MeterReading
    {
        return MeterReading::query()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'meter_number' => $customer->meter_number,
            'billing_period' => $period,
            'status' => $status,
            'reading_code' => $code,
            'previous_reading' => '100',
            'present_reading' => (string) (100 + (float) $consumption),
            'consumption' => $consumption,
            'read_on' => $period.'-15',
        ]);
    }

    #[DataProvider('reportPages')]
    public function test_each_report_opens_prints_and_exports_for_an_admin(Page $page): void
    {
        [$owner] = $this->ownerWithCompany();

        $this->actingAs($owner)->get(route($page->value))->assertSee($page->label());
        $this->actingAs($owner)->get(route($page->value.'.print'))->assertSee($page->label());
        $this->actingAs($owner)->get(route($page->value.'.export'))->assertDownload();
    }

    public function test_a_report_is_refused_to_a_member_whose_role_lacks_it(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::AgingReport, [Access::View]);

        $this->actingAs($member)->get(route('reports.collections'))->assertForbidden();
        $this->actingAs($member)->get(route('reports.collections.print'))->assertForbidden();
        $this->actingAs($member)->get(route('reports.collections.export'))->assertForbidden();
        $this->actingAs($member)->get(route('reports.aging'))->assertSee('Receivables Aging');
    }

    public function test_the_sidebar_lists_only_the_reports_the_role_allows(): void
    {
        [, $company] = $this->ownerWithCompany();
        $member = $this->memberWithAccess($company, Page::AgingReport, [Access::View]);

        $this->actingAs($member)->get(route('dashboard'))
            ->assertSee('Receivables Aging')
            ->assertDontSee('Daily Collections')
            ->assertDontSee('Billing Register');
    }

    public function test_daily_collections_total_the_posted_receipts_and_list_voided_ones_apart(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = $this->customerIn($company);
        $this->payment($customer, '2026-10-19', '500.00', ['received_by_id' => $owner->id]);
        $this->payment($customer, '2026-10-19', '250.50', ['method' => PaymentMethod::EWallet, 'reference_number' => 'GC-1', 'unapplied_amount' => '50.50', 'received_by_id' => $owner->id]);
        $this->payment($customer, '2026-10-19', '99.00', ['voided_at' => now(), 'void_reason' => 'Wrong customer']);
        $this->payment($customer, '2026-10-18', '1000.00');
        $this->payment($this->customerIn(Company::factory()->create()), '2026-10-19', '777.00');

        $result = $this->runReport(Page::CollectionsReport, $company);

        $this->assertSame('750.50', $result['summary']['Collected']);
        $this->assertSame('2', $result['summary']['Receipts']);
        $this->assertSame('50.50', $result['summary']['Kept as advance credit']);
        $this->assertSame('1 · 99.00', $result['summary']['Voided receipts']);
        $this->assertSame([['Cash', 1, 50000], [PaymentMethod::EWallet->label(), 1, 25050]], $result['tables']['By payment method']->rows);
        $this->assertSame([$owner->name, 2, 75050], $result['tables']['By cashier']->rows[0]);
        $this->assertSame('Wrong customer', $result['tables']['Voided receipts']->rows[0][4]);
    }

    public function test_daily_collections_narrow_to_a_cashier_and_a_zone(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $zone = ClusterType::factory()->for($company)->create(['name' => 'Zone 1']);
        $inZone = $this->customerIn($company, $zone);
        $elsewhere = $this->customerIn($company);
        $cashier = User::factory()->memberOf($company)->create();
        $this->payment($inZone, '2026-10-19', '100.00', ['received_by_id' => $cashier->id]);
        $this->payment($inZone, '2026-10-19', '200.00', ['received_by_id' => $owner->id]);
        $this->payment($elsewhere, '2026-10-19', '400.00', ['received_by_id' => $cashier->id]);

        $byCashier = $this->runReport(Page::CollectionsReport, $company, ['cashier' => (string) $cashier->id]);
        $byZone = $this->runReport(Page::CollectionsReport, $company, ['zone' => (string) $zone->id]);

        $this->assertSame('500.00', $byCashier['summary']['Collected']);
        $this->assertSame('300.00', $byZone['summary']['Collected']);
    }

    public function test_receivables_aging_puts_each_unpaid_balance_in_its_bucket(): void
    {
        [, $company] = $this->ownerWithCompany();
        $zone = ClusterType::factory()->for($company)->create(['name' => 'Zone 1']);
        $customer = $this->customerIn($company, $zone);
        $this->bill($customer, '2026-10-19', '10.00');
        $this->bill($customer, '2026-10-18', '20.00');
        $this->bill($customer, '2026-09-19', '30.00');
        $this->bill($customer, '2026-09-18', '40.00', ['status' => InvoiceStatus::PartiallyPaid, 'balance' => '15.00']);
        $this->bill($customer, '2026-07-21', '50.00');
        $this->bill($customer, '2026-07-20', '60.00');
        $this->bill($customer, '2026-06-01', '70.00', ['status' => InvoiceStatus::Paid, 'balance' => '0.00']);

        $result = $this->runReport(Page::AgingReport, $company);

        $this->assertSame(
            [$customer->account_number, $customer->fullName(), 'Zone 1', 1000, 5000, 1500, 5000, 6000, 18500],
            $result['tables']['By account']->rows[0],
        );
        $this->assertSame('175.00', $result['summary']['Past due']);
        $this->assertSame('60.00', $result['summary']['Over 90 days']);
    }

    public function test_the_billing_register_breaks_each_bill_into_its_parts_and_leaves_out_voided_bills(): void
    {
        [, $company] = $this->ownerWithCompany();
        $customer = $this->customerIn($company);
        $reading = $this->reading($customer, '2026-10', '12', MeterReadingStatus::Billed);
        $invoice = $this->bill($customer, '2026-10-05', '545.00', ['meter_reading_id' => $reading->id]);
        foreach ([
            [InvoiceLineKind::Water, '400.00'],
            [InvoiceLineKind::Charge, '50.00'],
            [InvoiceLineKind::Surcharge, '20.00'],
            [InvoiceLineKind::Adjustment, '-25.00'],
            [InvoiceLineKind::Tax, '100.00'],
        ] as [$kind, $amount]) {
            $invoice->lines()->create(['kind' => $kind, 'description' => $kind->value, 'amount' => $amount]);
        }
        $this->bill($customer, '2026-10-06', '999.00', ['status' => InvoiceStatus::Void, 'voided_at' => now()]);
        $this->bill($customer, '2026-09-30', '300.00');

        $result = $this->runReport(Page::BillingReport, $company);

        $this->assertSame([12.0, 40000, 5000, 2000, -2500, 10000, 54500], array_slice($result['tables']['Bills']->rows[0], 6, 7));
        $this->assertCount(1, $result['tables']['Bills']->rows);
        $this->assertSame('545.00', $result['summary']['Amount billed']);
    }

    public function test_voids_and_adjustments_list_the_memos_issued_and_records_voided_on_the_days(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = $this->customerIn($company);
        $invoice = $this->bill($customer, '2026-10-01', '500.00');
        $memo = fn (AdjustmentType $type, string $amount, array $attributes = []): InvoiceAdjustment => InvoiceAdjustment::createNumbered($company, [
            'type' => $type,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'reason_category' => $type === AdjustmentType::Credit ? AdjustmentReason::LeakAdjustment : AdjustmentReason::Undercharge,
            'reason' => 'Checked on site',
            'amount' => $amount,
            'issued_on' => '2026-10-10',
            'created_by_id' => $owner->id,
            ...$attributes,
        ]);
        $memo(AdjustmentType::Credit, '75.00');
        $memo(AdjustmentType::Debit, '30.00');
        $memo(AdjustmentType::Credit, '10.00', ['voided_at' => now(), 'void_reason' => 'Duplicate']);
        $memo(AdjustmentType::Credit, '5.00', ['issued_on' => '2026-09-30']);
        $this->payment($customer, '2026-10-02', '120.00', ['voided_at' => now(), 'void_reason' => 'Bounced check', 'voided_by_id' => $owner->id]);

        $result = $this->runReport(Page::AdjustmentsReport, $company);

        $this->assertSame('1 · 75.00', $result['summary']['Credit memos']);
        $this->assertSame('1 · 30.00', $result['summary']['Debit memos']);
        $this->assertCount(3, $result['tables']['Credit and debit memos issued']->rows);
        $this->assertSame([7500, 3000], array_slice($result['tables']['Credit and debit memos issued']->totals, 7, 2));
        $this->assertSame(['Duplicate'], array_column($result['tables']['Voided memos']->rows, 4));
        $this->assertSame(['Bounced check', $owner->name, '2026-10-19'], array_slice($result['tables']['Voided receipts']->rows[0], 4, 3));
    }

    public function test_collection_efficiency_compares_billed_and_collected_month_by_month(): void
    {
        [, $company] = $this->ownerWithCompany();
        $customer = $this->customerIn($company);
        $this->bill($customer, '2026-09-05', '1000.00', ['status' => InvoiceStatus::PartiallyPaid, 'balance' => '200.00']);
        $this->bill($customer, '2026-10-05', '500.00');
        $this->payment($customer, '2026-09-20', '800.00');

        $result = $this->runReport(Page::EfficiencyReport, $company, ['from_period' => '2026-09', 'period' => '2026-10']);

        $this->assertSame([
            ['September 2026', 100000, 80000, 80.0, 20000],
            ['October 2026', 50000, 0, 0.0, 50000],
        ], $result['tables']['By month']->rows);
        $this->assertSame(['Total', 150000, 80000, 53.3, 70000], $result['tables']['By month']->totals);
    }

    public function test_water_consumption_counts_confirmed_readings_and_lists_meters_with_no_usage(): void
    {
        [, $company] = $this->ownerWithCompany();
        $zone = ClusterType::factory()->for($company)->create(['name' => 'Zone 1']);
        $big = $this->customerIn($company, $zone);
        $idle = $this->customerIn($company, $zone);
        $this->reading($big, '2026-10', '30');
        $this->reading($big, '2026-09', '20');
        $this->reading($idle, '2026-10', '0', MeterReadingStatus::Billed);
        $this->reading($this->customerIn($company, $zone), '2026-10', '500', MeterReadingStatus::Review);

        $result = $this->runReport(Page::ConsumptionReport, $company, ['period' => '2026-10']);

        $this->assertSame([['Zone 1', 2, 30.0, 15.0, 100.0]], $result['tables']['By zone']->rows);
        $this->assertSame([$big->account_number, $big->fullName(), 'Zone 1', 30.0, 20.0, 50.0], $result['tables']['Largest users']->rows[0]);
        $this->assertSame([$idle->account_number], array_column($result['tables']['Meters with no usage']->rows, 0));
    }

    public function test_reading_progress_counts_meters_read_and_lists_those_not_read(): void
    {
        [, $company] = $this->ownerWithCompany();
        $zone = ClusterType::factory()->for($company)->create(['name' => 'Zone 1']);
        $read = $this->customerIn($company, $zone);
        $estimated = $this->customerIn($company, $zone);
        $unread = $this->customerIn($company, $zone);
        $this->customerIn($company, $zone, ['status' => 'pending']);
        $this->reading($read, '2026-10', '10');
        $this->reading($estimated, '2026-10', '8', MeterReadingStatus::Review, ReadingCode::NoAccess);
        $this->reading($unread, '2026-09', '9');

        $result = $this->runReport(Page::ReadingsReport, $company, ['period' => '2026-10']);

        $this->assertSame([['Zone 1', 3, 2, 1, 66.7, 1, 1, 0, 1]], $result['tables']['By zone']->rows);
        $this->assertSame([[$unread->account_number, $unread->fullName(), 'Zone 1', $unread->meter_number, 'September 2026']], $result['tables']['Meters not read yet']->rows);
    }

    public function test_the_disconnection_report_counts_notices_issued_on_the_days_by_outcome(): void
    {
        [, $company] = $this->ownerWithCompany();
        $customer = $this->customerIn($company);
        foreach ([[DisconnectionNoticeStatus::Settled, '2026-10-02'], [DisconnectionNoticeStatus::Pending, '2026-10-12'], [DisconnectionNoticeStatus::Disconnected, '2026-09-20']] as [$status, $issuedOn]) {
            DisconnectionNotice::createNumbered($company, [
                'customer_id' => $customer->id,
                'status' => $status,
                'overdue_bills' => 2,
                'overdue_amount' => '400.00',
                'oldest_due_date' => '2026-08-01',
                'issued_on' => $issuedOn,
                'deadline' => '2026-10-20',
            ]);
        }

        $result = $this->runReport(Page::DisconnectionsReport, $company);

        $this->assertSame('2 · 800.00', $result['summary']['Notices issued']);
        $this->assertSame('1', $result['summary']['Settled']);
        $this->assertSame('0', $result['summary']['Disconnected']);
    }

    public function test_the_work_order_report_counts_orders_by_type_and_lists_open_ones_oldest_first(): void
    {
        [, $company] = $this->ownerWithCompany();
        $customer = $this->customerIn($company);
        WorkOrder::factory()->forCustomer($customer, WorkOrderType::LeakRepair)->requestedDaysAgo(2)->completed()->create(['fee_amount' => '150.00']);
        $oldest = WorkOrder::factory()->forCustomer($customer, WorkOrderType::LeakRepair)->requestedDaysAgo(40)->create();
        WorkOrder::factory()->forCustomer($customer, WorkOrderType::PipeRepair)->requestedDaysAgo(1)->onHold()->create();

        $result = $this->runReport(Page::WorkOrdersReport, $company);

        $this->assertSame(['Leak Repair', 1, 1, 0, 1, 2.0, 15000], $result['tables']['By type of order']->rows[0]);
        $this->assertSame($oldest->order_number, $result['tables']['Open orders today']->rows[0][0]);
        $this->assertSame(40, $result['tables']['Open orders today']->rows[0][3]);
    }

    public function test_unusable_filters_fall_back_to_the_defaults(): void
    {
        [, $company] = $this->ownerWithCompany();
        $otherZone = ClusterType::factory()->for(Company::factory()->create())->create();

        $filters = $this->runReport(Page::BillingReport, $company, ['from' => '2026-02-30', 'to' => 'soon', 'zone' => (string) $otherZone->id])['filters'];

        $this->assertSame(['2026-10-01', '2026-10-19', null], [$filters->from, $filters->to, $filters->zoneId]);
    }

    public function test_date_ranges_are_put_in_order_and_kept_within_a_year(): void
    {
        [, $company] = $this->ownerWithCompany();

        $swapped = $this->runReport(Page::BillingReport, $company, ['from' => '2026-10-10', 'to' => '2026-10-01'])['filters'];
        $long = $this->runReport(Page::BillingReport, $company, ['from' => '2020-01-01', 'to' => '2026-10-19'])['filters'];
        $future = $this->runReport(Page::BillingReport, $company, ['to' => '2027-01-01'])['filters'];

        $this->assertSame(['2026-10-01', '2026-10-10'], [$swapped->from, $swapped->to]);
        $this->assertSame('2025-10-19', $long->from);
        $this->assertSame('2026-10-19', $future->to);
    }

    public function test_the_csv_export_holds_plain_numbers_and_iso_dates(): void
    {
        [$owner, $company] = $this->ownerWithCompany();
        $customer = $this->customerIn($company);
        $payment = $this->payment($customer, '2026-10-19', '1234.50', ['received_by_id' => $owner->id]);

        $csv = $this->actingAs($owner)->get(route('reports.collections.export'))->streamedContent();

        $this->assertStringContainsString("{$payment->receipt_number},2026-10-19,{$customer->account_number}", $csv);
        $this->assertStringContainsString(',1234.50', $csv);
        $this->assertStringNotContainsString('1,234.50', $csv);
    }

    public function test_operation_setup_calls_cluster_types_zones(): void
    {
        [$owner] = $this->ownerWithCompany();

        $this->actingAs($owner)->get(route('company', ['tab' => 'operation-setup']))
            ->assertSee('aria-label="Zones"', escape: false)
            ->assertDontSee('Cluster Type');
    }
}
