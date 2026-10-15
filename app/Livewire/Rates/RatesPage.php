<?php

namespace App\Livewire\Rates;

use App\Enums\Access;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\RateRange;
use App\Models\RateSetup;
use App\Models\RateType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Rate types, their setup (minimum consumption, defective computation) and consumption ranges.
 */
#[Layout('layouts.app')]
#[Title('Rates')]
class RatesPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    /**
     * Rate types shown per page of the rate type table.
     */
    public const PER_PAGE = 8;

    /**
     * Minimum number of rows drawn in each table, so empty tables keep their shape.
     */
    public const MINIMUM_ROWS = 8;

    /**
     * Highest consumption value a range may end at.
     */
    public const MAX_RANGE_VALUE = 99999999;

    /**
     * Highest rate per count, the limit of DECIMAL(12,2).
     */
    public const MAX_RATE = 9999999999.99;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * The rate type open in the forms, from the URL.
     */
    #[Url(as: 'rateType', except: '')]
    public string $rateType = '';

    public string $rateTypeName = '';

    public string $rateTypeDescription = '';

    public string $minimumConsumption = '';

    public bool $defectiveUsesMinimumConsumption = false;

    public bool $defectiveUsesThreeMonthAverage = false;

    public string $rangeTo = '';

    public string $ratePerCount = '';

    public string $rangeDescription = '';

    protected function page(): Page
    {
        return Page::Rates;
    }

    public function mount(): void
    {
        $this->fillForms();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function selected(): ?RateType
    {
        $id = self::idFrom($this->rateType);

        return $id === null
            ? null
            : $this->company()->rateTypes()->with(['setup', 'ranges'])->find($id);
    }

    /**
     * Loads the selected rate type into the forms, or clears them.
     */
    private function fillForms(): void
    {
        unset($this->selected);
        $selected = $this->selected;

        // An id that isn't one of the company's rate types (e.g. from an old link) selects nothing.
        if ($selected === null) {
            $this->rateType = '';
        }

        $this->rateTypeName = $selected->name ?? '';
        $this->rateTypeDescription = $selected->description ?? '';
        $this->minimumConsumption = (string) ($selected?->setup?->minimum_consumption ?? '');
        $this->defectiveUsesMinimumConsumption = $selected?->setup?->defective_uses_minimum_consumption ?? false;
        $this->defectiveUsesThreeMonthAverage = $selected?->setup?->defective_uses_three_month_average ?? false;
        $this->reset('rangeTo', 'ratePerCount', 'rangeDescription');
        $this->resetValidation();
    }

    public function select(int $rateTypeId): void
    {
        $this->rateType = (string) $rateTypeId;
        $this->fillForms();
    }

    public function cancelSelection(): void
    {
        $this->rateType = '';
        $this->fillForms();
    }

    /**
     * Add a new rate type, or update the selected one.
     */
    public function saveRateType(): void
    {
        $selected = $this->selected;
        $updating = $this->rateType !== '';

        if (! $this->allowed($updating ? Access::Edit : Access::Add)) {
            return;
        }
        if ($updating && $selected === null) {
            $this->flash('Select a rate type first.', 'error');

            return;
        }

        $this->rateTypeName = trim($this->rateTypeName);
        $this->rateTypeDescription = trim($this->rateTypeDescription);

        $this->validate([
            'rateTypeName' => ['bail', 'required', 'max:255'],
            'rateTypeDescription' => ['nullable', 'max:1000'],
        ], [
            'rateTypeName.required' => 'The rate type field is required.',
            'rateTypeName.max' => 'The rate type must not be greater than 255 characters.',
            'rateTypeDescription.max' => 'The description must not be greater than 1000 characters.',
        ]);

        $taken = $this->company()->rateTypes()
            ->where('name', $this->rateTypeName)
            ->when($selected, fn ($query) => $query->whereKeyNot($selected->id))
            ->exists();
        if ($taken) {
            $this->addError('rateTypeName', 'The rate type has already been taken.');

            return;
        }

        $attributes = [
            'name' => $this->rateTypeName,
            'description' => $this->rateTypeDescription === '' ? null : $this->rateTypeDescription,
        ];

        try {
            if ($selected) {
                $selected->update($attributes);
            } else {
                $this->company()->rateTypes()->create($attributes);
                $this->reset('rateTypeName', 'rateTypeDescription');
            }
        } catch (UniqueConstraintViolationException) {
            $this->addError('rateTypeName', 'The rate type has already been taken.');

            return;
        }

        unset($this->selected);
        $this->resetValidation();
        $this->flash($selected ? 'Rate type updated.' : 'Rate type added.');
    }

    /**
     * Save the minimum consumption and defective computation of the selected rate type.
     */
    public function saveSetup(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $selected = $this->selected;
        if ($selected === null) {
            $this->flash('Select a rate type first.', 'error');

            return;
        }

        $this->minimumConsumption = trim($this->minimumConsumption);
        $this->validate([
            'minimumConsumption' => ['bail', 'nullable', 'regex:/^\d+$/', 'integer', 'max:'.self::MAX_RANGE_VALUE],
        ], [
            'minimumConsumption.regex' => 'The minimum consumption must be a whole number.',
            'minimumConsumption.integer' => 'The minimum consumption must be a whole number.',
            'minimumConsumption.max' => 'The minimum consumption must not be greater than '.self::MAX_RANGE_VALUE.'.',
        ]);

        RateSetup::query()->updateOrCreate(['rate_type_id' => $selected->id], [
            'minimum_consumption' => $this->minimumConsumption === '' ? null : (int) $this->minimumConsumption,
            'defective_uses_minimum_consumption' => $this->defectiveUsesMinimumConsumption,
            'defective_uses_three_month_average' => $this->defectiveUsesThreeMonthAverage,
        ]);

        $this->resetValidation('minimumConsumption');
        $this->flash("Rate setup saved for {$selected->name}.");
    }

    /**
     * Add a consumption range that starts right after the rate type's last range.
     */
    public function addRange(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $selected = $this->selected;
        if ($selected === null) {
            $this->flash('Select a rate type first.', 'error');

            return;
        }

        $this->rangeTo = trim($this->rangeTo);
        $this->ratePerCount = trim($this->ratePerCount);
        $this->rangeDescription = trim($this->rangeDescription);

        $rangeFrom = $this->nextRangeFrom($selected);
        $this->validate([
            'rangeTo' => ['bail', 'required', 'regex:/^\d+$/', 'integer', "min:{$rangeFrom}", 'max:'.self::MAX_RANGE_VALUE],
            'ratePerCount' => ['bail', 'required', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'max:'.self::MAX_RATE],
            'rangeDescription' => ['bail', 'required', 'max:255'],
        ], [
            'rangeTo.required' => 'The "To" range field is required.',
            'rangeTo.regex' => 'The "To" range must be a whole number.',
            'rangeTo.integer' => 'The "To" range must be a whole number.',
            'rangeTo.min' => "The \"To\" range must be at least {$rangeFrom}.",
            'rangeTo.max' => 'The "To" range must not be greater than '.self::MAX_RANGE_VALUE.'.',
            'ratePerCount.required' => 'The rate per count field is required.',
            'ratePerCount.regex' => 'The rate per count must be a number with up to 2 decimal places.',
            'ratePerCount.numeric' => 'The rate per count must be a number with up to 2 decimal places.',
            'ratePerCount.max' => 'The rate per count must not be greater than 9999999999.99.',
            'rangeDescription.required' => 'The range description field is required.',
            'rangeDescription.max' => 'The range description must not be greater than 255 characters.',
        ]);

        try {
            $added = DB::transaction(function () use ($selected, $rangeFrom): bool {
                // Someone else may have added a range since the form was checked.
                if ($this->nextRangeFrom($selected) !== $rangeFrom) {
                    return false;
                }

                $selected->ranges()->create([
                    'range_from' => $rangeFrom,
                    'range_to' => (int) $this->rangeTo,
                    'rate' => $this->ratePerCount,
                    'description' => $this->rangeDescription,
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $added = false;
        }

        if (! $added) {
            unset($this->selected);
            $this->addError('ranges', 'The ranges changed while you were editing. Check the new "from" value and try again.');

            return;
        }

        unset($this->selected);
        $this->reset('rangeTo', 'ratePerCount', 'rangeDescription');
        $this->resetValidation();
    }

    /**
     * Delete the last (highest) consumption range; earlier ones would leave a gap.
     */
    public function deleteRange(int $rangeId): void
    {
        if (! $this->allowed(Access::Delete)) {
            return;
        }

        $selected = $this->selected;
        if ($selected === null) {
            $this->flash('Select a rate type first.', 'error');

            return;
        }

        $lastRangeId = RateRange::query()->where('rate_type_id', $selected->id)->orderByDesc('range_to')->value('id');
        if ($lastRangeId !== $rangeId) {
            $this->addError('ranges', 'Only the last consumption range can be deleted.');

            return;
        }

        RateRange::query()->whereKey($rangeId)->where('rate_type_id', $selected->id)->delete();

        unset($this->selected);
        $this->resetValidation();
    }

    /**
     * The locked "from" value for the next range: 0, or the last range's end + 1.
     */
    private function nextRangeFrom(RateType $rateType): int
    {
        $max = RateRange::query()->where('rate_type_id', $rateType->id)->max('range_to');

        return $max === null ? 0 : (int) $max + 1;
    }

    public function render(): View
    {
        $search = trim($this->search);
        $query = $this->company()->rateTypes()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('id');

        $selected = $this->selected;

        return view('livewire.rates.rates-page', [
            'rateTypes' => $this->paginateClamped($query, self::PER_PAGE),
            'selected' => $selected,
            'nextRangeFrom' => $selected ? (($selected->ranges->last()?->range_to ?? -1) + 1) : 0,
            'access' => $this->access,
        ]);
    }
}
