<?php

namespace App\Livewire\Company;

use App\Enums\Access;
use App\Enums\LookupType;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Models\CompanyLookup;
use App\Models\MeterType;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A company lookup list (account, meter and cluster types, departments): add, search,
 * toggle active, remove, and restore removed entries. Account types also have a Tax exempt
 * flag, and meter types their number of dials.
 */
class LookupTable extends Component
{
    use GuardsPageAccess;

    /**
     * Minimum number of rows drawn in each lookup table, so empty tables keep their shape.
     */
    public const MINIMUM_ROWS = 6;

    #[Locked]
    public string $type;

    public string $name = '';

    public string $search = '';

    public bool $showingRemoved = false;

    public ?string $rowError = null;

    public ?string $restoreError = null;

    protected function page(): Page
    {
        return Page::CompanySetup;
    }

    public function mount(string $type): void
    {
        $this->type = LookupType::from($type)->value;
    }

    private function lookupType(): LookupType
    {
        return LookupType::from($this->type);
    }

    /**
     * The company's entries of this list, removed ones included.
     *
     * @return Builder<CompanyLookup>
     */
    private function entries(): Builder
    {
        $class = $this->lookupType()->modelClass();

        return $class::withTrashed()->where('company_id', $this->company()->id);
    }

    public function add(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $label = strtolower($this->lookupType()->label());
        $this->name = trim($this->name);

        $this->validate(['name' => ['bail', 'required', 'max:255']], [
            'name.required' => "The {$label} field is required.",
            'name.max' => "The {$label} must not be greater than 255 characters.",
        ]);

        $duplicate = $this->entries()->whereNull('deleted_at')->where('name', $this->name)->exists();
        if ($duplicate) {
            $this->addError('name', "This {$label} already exists.");

            return;
        }

        $this->entries()->create(['company_id' => $this->company()->id, 'name' => $this->name]);

        $this->reset('name');
        $this->resetValidation();
    }

    /**
     * Turn an entry's active flag on or off.
     */
    public function toggleActive(int $id): void
    {
        $this->rowError = null;

        if (! $this->lookupType()->tracksActive() || ! $this->allowed(Access::Edit)) {
            return;
        }

        $item = $this->entries()->whereNull('deleted_at')->find($id);
        if ($item === null) {
            $this->rowError = 'That entry no longer exists. The list has been refreshed.';

            return;
        }

        $item->update(['is_active' => ! $item->is_active]);
    }

    /**
     * Turn an account type's tax exempt flag on or off.
     */
    public function toggleTaxExempt(int $id): void
    {
        $this->rowError = null;

        if (! $this->lookupType()->tracksTaxExempt() || ! $this->allowed(Access::Edit)) {
            return;
        }

        $item = $this->entries()->whereNull('deleted_at')->find($id);
        if ($item === null) {
            $this->rowError = 'That entry no longer exists. The list has been refreshed.';

            return;
        }

        $item->update(['is_tax_exempt' => ! $item->is_tax_exempt]);
    }

    /**
     * Set the number of dials of a meter type; blank clears it.
     */
    public function setDialDigits(int $id, string $value): void
    {
        $this->rowError = null;

        if (! $this->lookupType()->tracksDialDigits() || ! $this->allowed(Access::Edit)) {
            return;
        }

        $item = $this->entries()->whereNull('deleted_at')->find($id);
        if ($item === null) {
            $this->rowError = 'That entry no longer exists. The list has been refreshed.';

            return;
        }

        $value = trim($value);
        if ($value !== '' && (! preg_match('/^\d{1,2}$/', $value) || (int) $value < MeterType::MIN_DIGITS || (int) $value > MeterType::MAX_DIGITS)) {
            $this->rowError = 'The number of dials must be a whole number from '.MeterType::MIN_DIGITS.' to '.MeterType::MAX_DIGITS.'.';

            return;
        }

        $item->update(['dial_digits' => $value === '' ? null : (int) $value]);
    }

    /**
     * Remove (soft delete) an entry; it can be restored from the removed list.
     */
    public function remove(int $id): void
    {
        $this->rowError = null;

        if (! $this->allowed(Access::Delete)) {
            return;
        }

        $item = $this->entries()->whereNull('deleted_at')->find($id);
        if ($item === null) {
            $this->rowError = 'That entry no longer exists. The list has been refreshed.';

            return;
        }

        $item->delete();
    }

    /**
     * Bring a removed entry back, unless an entry with the same name was added since.
     */
    public function restore(int $id): void
    {
        $this->restoreError = null;

        if (! $this->lookupType()->showsRemoved() || ! $this->allowed(Access::Edit)) {
            return;
        }

        $item = $this->entries()->onlyTrashed()->find($id);
        if ($item === null) {
            $this->restoreError = 'That entry no longer exists. The list has been refreshed.';

            return;
        }

        $duplicate = $this->entries()->whereNull('deleted_at')->where('name', $item->name)->exists();
        if ($duplicate) {
            $this->restoreError = "\"{$item->name}\" already exists in the ".strtolower($this->lookupType()->plural()).' list.';

            return;
        }

        $item->restore();
    }

    public function closeRemoved(): void
    {
        $this->showingRemoved = false;
        $this->restoreError = null;
    }

    public function render(): View
    {
        $search = trim($this->search);
        $timezone = $this->company()->timezone;

        $items = $this->entries()
            ->whereNull('deleted_at')
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->get();

        $removed = $this->showingRemoved
            ? $this->entries()->onlyTrashed()->orderByDesc('deleted_at')->get()
                ->map(fn (CompanyLookup $item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'removedAt' => $item->deleted_at->toIso8601String(),
                    'removedOn' => Format::date($item->deleted_at, $timezone),
                ])
            : collect();

        return view('livewire.company.lookup-table', [
            'config' => $this->lookupType(),
            'items' => $items,
            'removed' => $removed,
            'searching' => $search !== '',
        ]);
    }
}
