<?php

namespace App\Livewire\MeterReadings;

use App\Enums\Access;
use App\Enums\DiscrepancySeverity;
use App\Enums\Page;
use App\Enums\SyncItemStatus;
use App\Enums\SyncItemType;
use App\Enums\SyncResolution;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Models\SyncItem;
use App\Support\Billing\BillingException;
use App\Support\Reader\SyncItems;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Discrepancies: readings uploaded from meter reader phones that differ from the live data. Held
 * ones were not saved; a supervisor accepts the printed bill, re-bills it with the current figures,
 * or rejects the reading. Info ones were saved and are listed for audit.
 */
#[Layout('layouts.app')]
#[Title('Discrepancies')]
class DiscrepanciesPage extends Component
{
    use GuardsPageAccess, WithPagination;

    public const PER_PAGE = 25;

    public const FILTERS = ['held' => 'Held', 'info' => 'Info', 'resolved' => 'Resolved'];

    #[Url(as: 'show', except: 'held')]
    public string $filter = 'held';

    public ?int $selectedId = null;

    public string $note = '';

    protected function page(): Page
    {
        return Page::MeterReadings;
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
        $this->closePanel();
    }

    public function select(int $id): void
    {
        $this->selectedId = $this->items()->whereKey($id)->exists() ? $id : null;
        $this->note = '';
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->selectedId = null;
        $this->note = '';
    }

    public function resolve(string $resolution): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $resolution = SyncResolution::tryFrom($resolution);
        $item = $this->selectedId === null ? null : SyncItem::query()->find($this->selectedId);
        if ($resolution === null || $item === null) {
            return;
        }

        $this->validate(['note' => ['required', 'string', 'max:500']], [
            'note.required' => 'Say why, for the audit trail.',
            'note.max' => 'Keep the note to 500 characters.',
        ]);

        try {
            SyncItems::resolve($item, $resolution, trim($this->note), $this->user());
        } catch (BillingException $exception) {
            $this->flash($exception->getMessage(), 'error');

            return;
        }

        $this->flash("Account {$item->customer?->account_number}: {$resolution->label()}.");
        $this->closePanel();
    }

    /**
     * @return Builder<SyncItem>
     */
    private function items(): Builder
    {
        $query = SyncItem::query()->where('type', SyncItemType::Reading);

        return match ($this->filter) {
            'info' => $query->where('status', SyncItemStatus::Applied)->whereNull('resolved_at')
                ->whereHas('discrepancies', fn (Builder $discrepancies) => $discrepancies->where('severity', DiscrepancySeverity::Info)),
            'resolved' => $query->whereNotNull('resolved_at'),
            default => $query->where('status', SyncItemStatus::Held),
        };
    }

    public function render(): View
    {
        $items = $this->paginateClamped(
            $this->items()->with(['customer', 'device.user', 'discrepancies', 'invoice', 'resolvedBy'])->latest('id'),
            self::PER_PAGE,
        );

        return view('livewire.meter-readings.discrepancies-page', [
            'items' => $items,
            'heldCount' => SyncItem::query()->where('status', SyncItemStatus::Held)->count(),
            'selected' => $this->selectedId === null ? null : SyncItem::query()->with(['customer', 'device.user', 'discrepancies', 'invoice', 'resolvedBy'])->find($this->selectedId),
        ]);
    }
}
