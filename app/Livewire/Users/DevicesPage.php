<?php

namespace App\Livewire\Users;

use App\Enums\Access;
use App\Enums\Page;
use App\Enums\SyncItemStatus;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Models\Device;
use App\Support\Reader\Devices;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Devices: the phones registered to meter readers, with the invoice numbers each still holds.
 * Revoking one signs it out at once; it can still upload the readings it already took, and every one
 * is held for review.
 */
#[Layout('layouts.app')]
#[Title('Reader Devices')]
class DevicesPage extends Component
{
    use GuardsPageAccess;

    protected function page(): Page
    {
        return Page::Users;
    }

    public function revoke(int $id): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $device = Device::query()->whereNull('revoked_at')->find($id);
        if ($device === null) {
            return;
        }

        Devices::revoke($device, $this->user());
        $this->flash("{$device->name} is signed out.");
    }

    public function render(): View
    {
        return view('livewire.users.devices-page', [
            'devices' => Device::query()
                ->with(['user', 'numberBlocks' => fn ($blocks) => $blocks->whereNull('closed_at')->orderBy('first_number')])
                ->withCount(['syncItems as held_count' => fn ($items) => $items->where('status', SyncItemStatus::Held)])
                ->orderByRaw('revoked_at is not null')
                ->latest('last_seen_at')
                ->get(),
            'timezone' => $this->company()->timezone,
        ]);
    }
}
