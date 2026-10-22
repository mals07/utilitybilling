<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberedPerCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    private function existingOrder(Company $company, string $number): void
    {
        WorkOrder::factory()->create(['company_id' => $company->id, 'order_number' => $number]);
    }

    private function nextOrder(): WorkOrder
    {
        return WorkOrder::createNumbered($this->company, WorkOrder::factory()->raw(['company_id' => $this->company->id]));
    }

    public function test_first_record_of_a_company_is_numbered_000001(): void
    {
        $this->assertSame('000001', $this->nextOrder()->order_number);
    }

    public function test_next_number_follows_the_numerically_highest_not_the_alphabetically_highest(): void
    {
        $this->existingOrder($this->company, '999999');
        $this->existingOrder($this->company, '1000000');

        $this->assertSame('1000001', $this->nextOrder()->order_number);
    }

    public function test_numbers_of_another_company_do_not_affect_the_sequence(): void
    {
        $this->existingOrder($this->company, '000004');
        $this->existingOrder(Company::factory()->create(), '000050');

        $this->assertSame('000005', $this->nextOrder()->order_number);
    }
}
