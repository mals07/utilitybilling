<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\UsesMeterReaderApp;
use Tests\TestCase;

class BillingVectorsCommandTest extends TestCase
{
    use RefreshDatabase, UsesMeterReaderApp;

    private string $output = 'storage/framework/testing/billing-vectors.json';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMeterReaderApp();
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->output));

        parent::tearDown();
    }

    public function test_writes_each_customers_readings_with_the_bills_the_server_works_out(): void
    {
        $customer = $this->routeCustomer();
        @mkdir(dirname(base_path($this->output)), 0777, true);

        $this->artisan('billing:export-vectors', ['company' => $this->company->id, '--output' => $this->output])
            ->expectsOutput("Wrote 6 case(s) to {$this->output}.")
            ->assertSuccessful();

        $vectors = json_decode(file_get_contents(base_path($this->output)), true);
        $cases = collect($vectors['cases']);
        $this->assertSame([$customer->id], $cases->pluck('customer.id')->unique()->values()->all());

        // 15 cu.m.: 10 × 10.00 + 5 × 25.00.
        $fifteen = $cases->firstWhere('present_reading', '15');
        $this->assertSame(225_00, $fifteen['expected']['total_cents']);
        $this->assertSame(['0-10 cu.m.', '11-20 cu.m.'], array_column($fifteen['expected']['lines'], 'description'));

        // No usage needs review; no history means an estimate falls back to the 10 cu.m. minimum.
        $this->assertSame(['No usage since the last reading.'], $cases->firstWhere('present_reading', '0')['expected']['flags']);
        $this->assertSame(100_00, $cases->firstWhere('reading_code', 'defective')['expected']['total_cents']);
    }

    public function test_fails_for_an_unknown_company(): void
    {
        $this->artisan('billing:export-vectors', ['company' => 999999, '--output' => $this->output])
            ->expectsOutput('No company with id 999999.')
            ->assertFailed();
    }
}
