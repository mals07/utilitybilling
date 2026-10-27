<?php

namespace Tests\Feature;

use App\Enums\Access;
use App\Enums\Page;
use App\Enums\ReadingCode;
use App\Enums\SyncItemStatus;
use App\Enums\SyncResolution;
use App\Livewire\MeterReadings\DiscrepanciesPage;
use App\Livewire\MeterReadings\ReadingAssignmentsPage;
use App\Livewire\Users\DevicesPage;
use App\Models\ClusterType;
use App\Models\Device;
use App\Models\ReadingAssignment;
use App\Models\SyncItem;
use App\Support\Billing\MeterReadings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\UsesMeterReaderApp;
use Tests\TestCase;

class MeterReaderPagesTest extends TestCase
{
    use RefreshDatabase, UsesMeterReaderApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMeterReaderApp();
    }

    /**
     * A reading synced after the office read the same meter: held as a duplicate.
     */
    private function heldItem(): SyncItem
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $item = $this->readingItem($package, $customer, '15', '000001');
        MeterReadings::save($customer, $this->period, '14', ReadingCode::Normal, $this->today, $this->owner);
        $this->sync($token, $package, [$item]);

        return SyncItem::query()->withoutGlobalScopes()->sole();
    }

    public function test_assigning_a_cluster_gives_it_to_the_chosen_reader(): void
    {
        $cluster = ClusterType::factory()->create(['company_id' => $this->company->id, 'name' => 'Purok 2']);

        Livewire::actingAs($this->owner)->test(ReadingAssignmentsPage::class)
            ->call('assign', $cluster->id, (string) $this->reader->id)
            ->assertDispatched('flash', message: "Purok 2 assigned to {$this->reader->name}.", tone: 'success');

        $this->assertSame($this->reader->id, ReadingAssignment::query()->where('cluster_type_id', $cluster->id)->value('user_id'));
    }

    public function test_a_user_who_cannot_enter_readings_cannot_be_assigned(): void
    {
        $viewer = $this->memberWithAccess($this->company, Page::MeterReadings, [Access::View]);

        Livewire::actingAs($this->owner)->test(ReadingAssignmentsPage::class)
            ->call('assign', $this->cluster->id, (string) $viewer->id)
            ->assertDispatched('flash', message: 'Choose a reader who may enter meter readings.', tone: 'error');

        $this->assertSame($this->reader->id, ReadingAssignment::query()->sole()->user_id);
    }

    public function test_assigning_needs_edit_access(): void
    {
        $viewer = $this->memberWithAccess($this->company, Page::MeterReadings, [Access::View]);

        Livewire::actingAs($viewer)->test(ReadingAssignmentsPage::class)
            ->call('assign', $this->cluster->id, '')
            ->assertDispatched('flash', message: Access::Edit->deniedMessage(), tone: 'error');

        $this->assertSame(1, ReadingAssignment::query()->withoutGlobalScopes()->count());
    }

    public function test_held_readings_are_listed_and_resolving_one_needs_a_note(): void
    {
        $item = $this->heldItem();

        Livewire::actingAs($this->owner)->test(DiscrepanciesPage::class)
            ->assertSee('Already read or billed')
            ->call('select', $item->id)
            ->call('resolve', SyncResolution::Rejected->value)
            ->assertHasErrors(['note' => 'Say why, for the audit trail.'])
            ->set('note', 'Office reading kept.')
            ->call('resolve', SyncResolution::Rejected->value)
            ->assertHasNoErrors();

        $this->assertSame(SyncItemStatus::Rejected, $item->fresh()->status);
        $this->assertSame($this->owner->id, $item->fresh()->resolved_by_id);
    }

    public function test_resolving_needs_edit_access(): void
    {
        $item = $this->heldItem();
        $viewer = $this->memberWithAccess($this->company, Page::MeterReadings, [Access::View]);

        Livewire::actingAs($viewer)->test(DiscrepanciesPage::class)
            ->call('select', $item->id)
            ->set('note', 'Trying anyway.')
            ->call('resolve', SyncResolution::Rejected->value)
            ->assertDispatched('flash', message: Access::Edit->deniedMessage(), tone: 'error');

        $this->assertSame(SyncItemStatus::Held, $item->fresh()->status);
    }

    public function test_another_companys_held_readings_are_not_listed(): void
    {
        $this->heldItem();
        [$otherOwner] = $this->ownerWithCompany();

        Livewire::actingAs($otherOwner)->test(DiscrepanciesPage::class)
            ->assertDontSee('Already read or billed')
            ->assertSee('Nothing is held.');
    }

    public function test_signing_out_a_device_ends_its_access(): void
    {
        $token = $this->registerDevice();
        $device = Device::query()->withoutGlobalScopes()->sole();

        Livewire::actingAs($this->owner)->test(DevicesPage::class)
            ->assertSee('RDR-03')
            ->call('revoke', $device->id);

        $this->assertNotNull($device->fresh()->revoked_at);
        $this->withToken($token)->postJson(route('reader.packages.store'))->assertUnauthorized();
    }

    public function test_the_devices_page_shows_how_many_of_a_devices_open_numbers_are_not_on_an_invoice(): void
    {
        $customer = $this->routeCustomer();
        $token = $this->registerDevice();
        $package = $this->downloadPackage($token);
        $this->sync($token, $package, [$this->readingItem($package, $customer, '15', '000001')])->assertOk();

        Livewire::actingAs($this->owner)->test(DevicesPage::class)
            ->assertSee('000001–000010')
            ->assertSee('9 of 10 not on an invoice');
    }

    public function test_the_devices_page_needs_access_to_users(): void
    {
        $this->actingAs($this->reader)->get(route('users.devices'))->assertForbidden();
    }
}
