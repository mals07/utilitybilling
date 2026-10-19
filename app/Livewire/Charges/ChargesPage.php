<?php

namespace App\Livewire\Charges;

use App\Enums\Access;
use App\Enums\ChargeValueType;
use App\Enums\DistributionType;
use App\Enums\Page;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\ChargeElement;
use App\Models\ChargeType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Charge types, charge elements, and which elements each charge type applies (Charge Type Setup).
 */
#[Layout('layouts.app')]
#[Title('Charges')]
class ChargesPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    /**
     * Rows shown per page in the charge type and charge element tables.
     */
    public const PER_PAGE = 8;

    /**
     * Minimum number of rows drawn in each table, so empty tables keep their shape.
     */
    public const MINIMUM_ROWS = 8;

    #[Url(as: 'tq', except: '')]
    public string $typeSearch = '';

    #[Url(as: 'eq', except: '')]
    public string $elementSearch = '';

    /**
     * The charge type loaded into the form for updating.
     */
    #[Url(as: 'type', except: '')]
    public string $type = '';

    /**
     * The charge type open in the Charge Type Setup modal.
     */
    #[Url(as: 'setup', except: '')]
    public string $setup = '';

    public string $chargeTypeName = '';

    public string $chargeTypeDescription = '';

    public string $elementName = '';

    public string $elementDistribution = '';

    public bool $elementAfterDue = false;

    public bool $elementTaxable = false;

    /**
     * The charge element open in the "Update Charge Element" modal.
     */
    #[Locked]
    public ?int $editingElementId = null;

    public string $editName = '';

    public string $editDistribution = '';

    public bool $editAfterDue = false;

    public bool $editTaxable = false;

    public ?int $setupElementId = null;

    public string $setupValueType = 'fixed';

    public string $setupValue = '';

    /**
     * The setup row being edited inline.
     */
    #[Locked]
    public ?int $editingRowId = null;

    public string $rowValueType = 'fixed';

    public string $rowValue = '';

    public ?string $setupMessage = null;

    protected function page(): Page
    {
        return Page::Charges;
    }

    public function mount(): void
    {
        // Ids that aren't the company's charge types (e.g. from an old link) select nothing.
        if ($this->setupType === null) {
            $this->setup = '';
        }

        $this->fillChargeTypeForm();
    }

    public function updatedTypeSearch(): void
    {
        $this->resetPage('tpage');
    }

    public function updatedElementSearch(): void
    {
        $this->resetPage('epage');
    }

    private function findChargeType(string $id): ?ChargeType
    {
        $id = self::idFrom($id);

        return $id === null ? null : $this->company()->chargeTypes()->find($id);
    }

    #[Computed]
    public function selectedType(): ?ChargeType
    {
        return $this->findChargeType($this->type);
    }

    #[Computed]
    public function setupType(): ?ChargeType
    {
        return $this->findChargeType($this->setup);
    }

    /* ------------------------------ Charge types ----------------------------- */

    private function fillChargeTypeForm(): void
    {
        unset($this->selectedType);
        if ($this->selectedType === null) {
            $this->type = '';
        }
        $this->chargeTypeName = $this->selectedType->name ?? '';
        $this->chargeTypeDescription = $this->selectedType->description ?? '';
        $this->resetValidation(['chargeTypeName', 'chargeTypeDescription']);
    }

    public function selectType(int $id): void
    {
        $this->type = (string) $id;
        $this->fillChargeTypeForm();
    }

    public function cancelType(): void
    {
        $this->type = '';
        $this->fillChargeTypeForm();
    }

    /**
     * Add a new charge type, or update the selected one.
     */
    public function saveChargeType(): void
    {
        $updating = $this->type !== '';
        if (! $this->allowed($updating ? Access::Edit : Access::Add)) {
            return;
        }

        $selected = $this->selectedType;
        if ($updating && $selected === null) {
            $this->flash('That charge type no longer exists. The page has been refreshed.', 'error');

            return;
        }

        $this->chargeTypeName = trim($this->chargeTypeName);
        $this->chargeTypeDescription = trim($this->chargeTypeDescription);

        $this->validate([
            'chargeTypeName' => ['bail', 'required', 'max:255'],
            'chargeTypeDescription' => ['nullable', 'max:1000'],
        ], [
            'chargeTypeName.required' => 'The charge type field is required.',
            'chargeTypeName.max' => 'The charge type must not be greater than 255 characters.',
            'chargeTypeDescription.max' => 'The description must not be greater than 1000 characters.',
        ]);

        $taken = $this->company()->chargeTypes()
            ->where('name', $this->chargeTypeName)
            ->when($selected, fn ($query) => $query->whereKeyNot($selected->id))
            ->exists();
        if ($taken) {
            $this->addError('chargeTypeName', 'The charge type has already been taken.');

            return;
        }

        $attributes = [
            'name' => $this->chargeTypeName,
            'description' => $this->chargeTypeDescription === '' ? null : $this->chargeTypeDescription,
        ];

        try {
            if ($selected) {
                $selected->update($attributes);
            } else {
                $this->company()->chargeTypes()->create($attributes);
                $this->reset('chargeTypeName', 'chargeTypeDescription');
            }
        } catch (UniqueConstraintViolationException) {
            $this->addError('chargeTypeName', 'The charge type has already been taken.');

            return;
        }

        unset($this->selectedType, $this->setupType);
        $this->resetValidation();
        $this->flash($selected ? 'Charge type updated.' : 'Charge type added.');
    }

    /* ---------------------------- Charge elements ---------------------------- */

    /**
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>}
     */
    private static function elementValidation(string $name, string $distribution): array
    {
        return [
            'rules' => [
                $name => ['bail', 'required', 'max:255'],
                $distribution => ['bail', 'required', Rule::enum(DistributionType::class)],
            ],
            'messages' => [
                "{$name}.required" => 'The charge element field is required.',
                "{$name}.max" => 'The charge element must not be greater than 255 characters.',
                "{$distribution}.required" => 'Select a distribution type.',
                "{$distribution}.enum" => 'Select a distribution type.',
            ],
        ];
    }

    private function elementNameTaken(string $name, ?int $ignoreId = null): bool
    {
        return $this->company()->chargeElements()
            ->where('name', $name)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Add a new charge element.
     */
    public function addElement(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $this->elementName = trim($this->elementName);
        $validation = self::elementValidation('elementName', 'elementDistribution');
        $this->validate($validation['rules'], $validation['messages']);

        if ($this->elementNameTaken($this->elementName)) {
            $this->addError('elementName', 'The charge element has already been taken.');

            return;
        }

        try {
            $this->company()->chargeElements()->create([
                'name' => $this->elementName,
                'distribution_type' => $this->elementDistribution,
                'is_after_due' => $this->elementAfterDue,
                'is_taxable' => $this->elementTaxable,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->addError('elementName', 'The charge element has already been taken.');

            return;
        }

        $this->reset('elementName', 'elementDistribution', 'elementAfterDue', 'elementTaxable');
        $this->resetValidation();
        $this->flash('Charge element added.');
    }

    /**
     * Open the "Update Charge Element" modal.
     */
    public function editElement(int $id): void
    {
        $element = $this->company()->chargeElements()->find($id);
        if ($element === null) {
            $this->flash('That charge element no longer exists. The page has been refreshed.', 'error');

            return;
        }

        $this->editingElementId = $element->id;
        $this->editName = $element->name;
        $this->editDistribution = $element->distribution_type->value;
        $this->editAfterDue = $element->is_after_due;
        $this->editTaxable = $element->is_taxable;
        $this->resetValidation();
    }

    public function closeElementModal(): void
    {
        $this->editingElementId = null;
        $this->resetValidation();
    }

    public function updateElement(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $element = $this->editingElementId === null ? null : $this->company()->chargeElements()->find($this->editingElementId);
        if ($element === null) {
            $this->closeElementModal();
            $this->flash('That charge element no longer exists. The page has been refreshed.', 'error');

            return;
        }

        $this->editName = trim($this->editName);
        $validation = self::elementValidation('editName', 'editDistribution');
        $this->validate($validation['rules'], $validation['messages']);

        if ($this->elementNameTaken($this->editName, $element->id)) {
            $this->addError('editName', 'The charge element has already been taken.');

            return;
        }

        try {
            $element->update([
                'name' => $this->editName,
                'distribution_type' => $this->editDistribution,
                'is_after_due' => $this->editAfterDue,
                'is_taxable' => $this->editTaxable,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->addError('editName', 'The charge element has already been taken.');

            return;
        }

        $this->closeElementModal();
        unset($this->setupType);
        $this->flash('Charge element updated.');
    }

    /* --------------------------- Charge type setup --------------------------- */

    public function openSetup(int $id): void
    {
        $this->setup = (string) $id;
        $this->resetSetupForm();
    }

    public function closeSetup(): void
    {
        $this->setup = '';
        $this->resetSetupForm();
    }

    private function resetSetupForm(): void
    {
        unset($this->setupType);
        $this->reset('setupElementId', 'setupValueType', 'setupValue', 'editingRowId', 'rowValueType', 'rowValue', 'setupMessage');
        $this->resetValidation();
    }

    /**
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>}
     */
    private static function valueValidation(string $typeField, string $valueField, string $valueType): array
    {
        $max = ChargeValueType::tryFrom($valueType)?->maxValue() ?? ChargeValueType::Fixed->maxValue();
        $maxText = $max === 100.0 ? '100' : '9999999999.99';

        return [
            'rules' => [
                $typeField => ['bail', 'required', Rule::enum(ChargeValueType::class)],
                $valueField => ['bail', 'required', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', "max:{$max}"],
            ],
            'messages' => [
                "{$typeField}.required" => 'Select a value type.',
                "{$typeField}.enum" => 'Select a value type.',
                "{$valueField}.required" => 'The value field is required.',
                "{$valueField}.regex" => 'The value must be a number with up to 2 decimal places.',
                "{$valueField}.numeric" => 'The value must be a number with up to 2 decimal places.',
                "{$valueField}.max" => "The value must not be greater than {$maxText}.",
            ],
        ];
    }

    /**
     * Add a charge element, with its value, to the charge type in the setup modal.
     */
    public function addSetupElement(): void
    {
        $this->setupMessage = null;

        if (! $this->allowed(Access::Add)) {
            return;
        }

        $chargeType = $this->setupType;
        if ($chargeType === null) {
            $this->setupMessage = 'That charge type no longer exists. The page has been refreshed.';

            return;
        }

        $this->resetValidation();
        $this->setupValue = trim($this->setupValue);

        $elementError = match (true) {
            $this->setupElementId === null => 'Select a charge element.',
            ! $this->company()->chargeElements()->whereKey($this->setupElementId)->exists() => 'Select a charge element from the list.',
            $chargeType->elements()->where('charge_element_id', $this->setupElementId)->exists() => "This charge element is already set up for {$chargeType->name}.",
            default => null,
        };

        $validation = self::valueValidation('setupValueType', 'setupValue', $this->setupValueType);
        $validator = validator(
            ['setupValueType' => $this->setupValueType, 'setupValue' => $this->setupValue],
            $validation['rules'],
            $validation['messages'],
        );

        if ($elementError !== null || $validator->fails()) {
            if ($elementError !== null) {
                $this->addError('setupElementId', $elementError);
            }
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        try {
            $chargeType->elements()->create([
                'charge_element_id' => $this->setupElementId,
                'value_type' => $this->setupValueType,
                'value' => $this->setupValue,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->addError('setupElementId', "This charge element is already set up for {$chargeType->name}.");

            return;
        }

        $this->reset('setupElementId', 'setupValue');
    }

    public function startRowEdit(int $rowId): void
    {
        $row = $this->setupType?->elements()->find($rowId);
        if ($row === null) {
            return;
        }

        $this->editingRowId = $row->id;
        $this->rowValueType = $row->value_type->value;
        $this->rowValue = (string) $row->value;
        $this->resetValidation(['rowValueType', 'rowValue']);
    }

    public function cancelRowEdit(): void
    {
        $this->editingRowId = null;
        $this->resetValidation(['rowValueType', 'rowValue']);
    }

    /**
     * Save the inline changes to a row of the setup table.
     */
    public function saveRowEdit(): void
    {
        $this->setupMessage = null;

        if (! $this->allowed(Access::Edit) || $this->editingRowId === null) {
            return;
        }

        $chargeType = $this->setupType;
        if ($chargeType === null) {
            $this->setupMessage = 'That charge type no longer exists. The page has been refreshed.';

            return;
        }

        $this->rowValue = trim($this->rowValue);
        $validation = self::valueValidation('rowValueType', 'rowValue', $this->rowValueType);
        $this->validate($validation['rules'], $validation['messages']);

        $updated = $chargeType->elements()->whereKey($this->editingRowId)->update([
            'value_type' => $this->rowValueType,
            'value' => $this->rowValue,
        ]);

        if ($updated === 0) {
            $this->setupMessage = 'That row no longer exists. The table has been refreshed.';
        }

        $this->cancelRowEdit();
    }

    /**
     * Remove a charge element from the charge type.
     */
    public function deleteSetupRow(int $rowId): void
    {
        $this->setupMessage = null;

        if (! $this->allowed(Access::Delete)) {
            return;
        }

        $chargeType = $this->setupType;
        if ($chargeType === null) {
            $this->setupMessage = 'That charge type no longer exists. The page has been refreshed.';

            return;
        }

        $chargeType->elements()->whereKey($rowId)->delete();

        if ($this->editingRowId === $rowId) {
            $this->cancelRowEdit();
        }
    }

    /* -------------------------------- Render --------------------------------- */

    public function render(): View
    {
        $company = $this->company();
        $typeSearch = trim($this->typeSearch);
        $elementSearch = trim($this->elementSearch);

        $chargeTypes = $this->paginateClamped(
            $company->chargeTypes()
                ->when($typeSearch !== '', fn ($query) => $query->where('name', 'like', '%'.$typeSearch.'%'))
                ->orderBy('id'),
            self::PER_PAGE,
            'tpage',
        );

        $chargeElements = $this->paginateClamped(
            $company->chargeElements()
                ->when($elementSearch !== '', fn ($query) => $query->where('name', 'like', '%'.$elementSearch.'%'))
                ->orderBy('id'),
            self::PER_PAGE,
            'epage',
        );

        $setupType = $this->setupType;
        $setupRows = collect();
        $available = collect();

        if ($setupType !== null) {
            $setupRows = $setupType->elements()->with('chargeElement')->orderBy('id')->get();
            // Charge elements not yet added to this charge type.
            $available = $company->chargeElements()
                ->whereNotIn('id', $setupRows->pluck('charge_element_id'))
                ->orderBy('name')
                ->get()
                ->map(fn (ChargeElement $element): array => [
                    'id' => $element->id,
                    'name' => $element->name,
                    'type' => $element->distribution_type->label(),
                ]);
        }

        return view('livewire.charges.charges-page', [
            'chargeTypes' => $chargeTypes,
            'chargeElements' => $chargeElements,
            'selectedType' => $this->selectedType,
            'setupType' => $setupType,
            'setupRows' => $setupRows,
            'available' => $available,
            'timezone' => $company->timezone,
            'access' => $this->access,
        ]);
    }
}
