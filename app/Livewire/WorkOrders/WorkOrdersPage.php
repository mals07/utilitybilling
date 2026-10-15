<?php

namespace App\Livewire\WorkOrders;

use App\Enums\Access;
use App\Enums\Page;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Work Orders: the Submit Work Order form, the work orders table, and the Update/View Work Order modals.
 */
#[Layout('layouts.app')]
#[Title('Work Orders')]
class WorkOrdersPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithPagination;

    /**
     * Work orders shown per page of the table.
     */
    public const PER_PAGE = 10;

    /**
     * Minimum rows drawn in the table, so an empty table keeps its shape.
     */
    public const MINIMUM_ROWS = 8;

    public const ORDER_NUMBER_DIGITS = 6;

    /**
     * Customers listed under the Account No. field while searching.
     */
    public const CUSTOMER_MATCHES = 8;

    /**
     * Form fields shared by the Submit form and the Update modal => work_orders column.
     */
    public const FIELDS = [
        'orderType' => 'order_type',
        'meterTypeId' => 'meter_type_id',
        'meterNumber' => 'meter_number',
        'serialNumber' => 'serial_number',
        'description' => 'description',
        'assignedToId' => 'assigned_to_id',
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Only list orders still In Progress.
     */
    #[Url(as: 'pending', except: false)]
    public bool $pendingOnly = false;

    /* ------------------------------ Submit form ------------------------------ */

    /**
     * What is typed in the Account No. field.
     */
    public string $customerSearch = '';

    /**
     * The customer picked from the Account No. matches.
     */
    public string $customerId = '';

    /**
     * @var array<string, string>
     */
    public array $values = [];

    /* ------------------------- Update / View modals -------------------------- */

    /**
     * The order open in the Update Work Order modal.
     */
    public string $editingId = '';

    /**
     * The order open in the View Work Order modal.
     */
    public string $viewingId = '';

    /**
     * @var array<string, string>
     */
    public array $edit = [];

    public ?string $notice = null;

    /* --------------------------- Default role modal -------------------------- */

    public bool $choosingRole = false;

    /**
     * The role picked in the modal; '' lists every active user.
     */
    public string $roleChoice = '';

    protected function page(): Page
    {
        return Page::WorkOrders;
    }

    public function mount(): void
    {
        $this->values = self::emptyValues();
        $this->edit = [...self::emptyValues(), 'status' => ''];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPendingOnly(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, string>
     */
    private static function emptyValues(): array
    {
        return array_fill_keys(array_keys(self::FIELDS), '');
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'values.') || str_starts_with($property, 'edit.')) {
            $this->resetValidation($property);
        }
    }

    /* ------------------------------- Customers ------------------------------- */

    #[Computed]
    public function chosenCustomer(): ?Customer
    {
        $id = self::idFrom($this->customerId);

        return $id === null ? null : $this->company()->customers()->find($id);
    }

    /**
     * Customers whose account number or name matches every word typed in the Account No. field.
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerMatches(): Collection
    {
        $words = preg_split('/\s+/', trim($this->customerSearch), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === [] || $this->customerId !== '') {
            return collect();
        }

        $query = $this->company()->customers()->select(['id', 'account_number', 'first_name', 'last_name', 'meter_type_id']);
        foreach ($words as $word) {
            $query->where(fn (Builder $query) => $query
                ->where('account_number', 'like', "%{$word}%")
                ->orWhere('first_name', 'like', "%{$word}%")
                ->orWhere('last_name', 'like', "%{$word}%"));
        }

        return $query->orderBy('account_number')->limit(self::CUSTOMER_MATCHES)->get();
    }

    /**
     * Typing in the Account No. field after a customer was picked starts a new search.
     */
    public function updatedCustomerSearch(): void
    {
        if ($this->customerId !== '' && $this->customerSearch !== $this->chosenCustomer?->account_number) {
            $this->customerId = '';
            unset($this->chosenCustomer);
        }
        unset($this->customerMatches);
        $this->resetValidation('customerId');
    }

    public function chooseCustomer(int $id): void
    {
        $customer = $this->company()->customers()->find($id);
        if ($customer === null) {
            return;
        }

        $this->customerId = (string) $customer->id;
        $this->customerSearch = $customer->account_number;
        unset($this->chosenCustomer, $this->customerMatches);

        // Start from the meter type the customer already has.
        if ($customer->meter_type_id !== null) {
            $this->values['meterTypeId'] = (string) $customer->meter_type_id;
        }

        $this->resetValidation(['customerId', 'values.meterTypeId']);
    }

    /* -------------------------------- Options -------------------------------- */

    /**
     * Active meter types from Operation Setup, plus the one an order or customer already has.
     *
     * @return array<int, string>
     */
    private function meterTypeOptions(?int $keep): array
    {
        return $this->company()->meterTypes()
            ->withTrashed()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('is_active', true)->whereNull('deleted_at'))
                ->when($keep, fn (Builder $query) => $query->orWhere('id', $keep)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Active users of the company, narrowed to the default role when one is chosen, plus the
     * user an order is already assigned to.
     *
     * @return array<int, string>
     */
    private function assigneeOptions(?int $keep): array
    {
        $company = $this->company();
        $roleId = $company->work_order_assignee_role_id;

        return $company->members()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->whereNull('deactivated_at')
                    ->when($roleId !== null, fn (Builder $query) => $query->where('role_id', $roleId)))
                ->when($keep, fn (Builder $query) => $query->orWhere('id', $keep)))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'name', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->name ?: User::fullName($user->first_name, $user->last_name)])
            ->all();
    }

    /* ------------------------------- Validation ------------------------------ */

    /**
     * Checks one set of form values; errors are keyed "{$key}.{field}".
     *
     * @param  array<string, string>  $values
     * @return array{errors: array<string, string>, attributes: array<string, mixed>}
     */
    private function checkValues(array $values, string $key, ?WorkOrder $existing): array
    {
        $values = array_map(fn ($value): string => trim((string) $value), $values);

        $rules = [
            'orderType' => ['required', Rule::enum(WorkOrderType::class)],
            'meterTypeId' => ['nullable', 'regex:/^[1-9]\d{0,9}$/'],
            'meterNumber' => ['nullable', 'max:50'],
            'serialNumber' => ['nullable', 'max:50'],
            'description' => ['nullable', 'max:2000'],
            'assignedToId' => ['required', 'regex:/^[1-9]\d{0,9}$/'],
        ];
        $messages = [
            'orderType.required' => 'Select an order type.',
            'orderType.enum' => 'Select an order type from the list.',
            'meterTypeId.regex' => 'Select a meter type from the list.',
            'meterNumber.max' => 'The meter number must not be greater than 50 characters.',
            'serialNumber.max' => 'The serial number must not be greater than 50 characters.',
            'description.max' => 'The description must not be greater than 2000 characters.',
            'assignedToId.required' => 'Select who the work order is assigned to.',
            'assignedToId.regex' => 'Select a user from the list.',
        ];

        $errors = [];
        foreach (validator($values, $rules, $messages)->errors()->messages() as $field => $fieldMessages) {
            $errors["{$key}.{$field}"] = $fieldMessages[0];
        }

        if (! isset($errors["{$key}.meterTypeId"]) && $values['meterTypeId'] !== ''
            && ! array_key_exists((int) $values['meterTypeId'], $this->meterTypeOptions($existing?->meter_type_id))) {
            $errors["{$key}.meterTypeId"] = 'Select a meter type from the list.';
        }
        if (! isset($errors["{$key}.assignedToId"])
            && ! array_key_exists((int) $values['assignedToId'], $this->assigneeOptions($existing?->assigned_to_id))) {
            $errors["{$key}.assignedToId"] = 'Select a user from the list.';
        }

        $attributes = [];
        foreach (self::FIELDS as $field => $column) {
            $value = $values[$field];
            $attributes[$column] = match (true) {
                $value === '' => null,
                str_ends_with($field, 'Id') => (int) $value,
                default => $value,
            };
        }

        return ['errors' => $errors, 'attributes' => $attributes];
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function showErrors(array $errors): void
    {
        foreach ($errors as $key => $message) {
            $this->addError($key, $message);
        }
        $this->dispatch('focus-first-error');
    }

    /* ------------------------------- Submitting ------------------------------ */

    /**
     * The next order number in the company's sequence: one more than the highest so far.
     */
    private function nextOrderNumber(): string
    {
        $highest = $this->company()->workOrders()->pluck('order_number')
            ->reduce(fn (int $max, string $number): int => max($max, (int) $number), 0);

        return str_pad((string) ($highest + 1), self::ORDER_NUMBER_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Save the new work order as In Progress.
     */
    public function submit(): void
    {
        if (! $this->allowed(Access::Add)) {
            return;
        }

        $this->resetValidation();
        unset($this->chosenCustomer);
        $customer = $this->chosenCustomer;

        $checked = $this->checkValues($this->values, 'values', null);
        $errors = $checked['errors'];
        if ($customer === null) {
            $errors = ['customerId' => 'Search for a customer and pick one from the list.', ...$errors];
        }

        if ($errors !== []) {
            $this->showErrors($errors);

            return;
        }

        // Two orders submitted at the same moment may pick the same number; the unique index
        // rejects the second, which then takes the next one.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $orderNumber = $this->nextOrderNumber();

            try {
                $this->company()->workOrders()->create([
                    ...$checked['attributes'],
                    'order_number' => $orderNumber,
                    'customer_id' => $customer->id,
                    'status' => WorkOrderStatus::InProgress,
                    'requested_by_id' => $this->user()->id,
                    'requested_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $this->resetForm();
            $this->resetPage();
            $this->flash("Work order {$orderNumber} submitted.");

            return;
        }

        $this->flash("The work order couldn't be submitted. Please try again.", 'error');
    }

    private function resetForm(): void
    {
        $this->reset('customerSearch', 'customerId');
        unset($this->chosenCustomer, $this->customerMatches);
        $this->values = self::emptyValues();
        $this->resetValidation();
    }

    /* ----------------------- Update / View work order ------------------------ */

    private function findOrder(string $id): ?WorkOrder
    {
        $id = self::idFrom($id);

        return $id === null
            ? null
            : $this->company()->workOrders()->with(['customer', 'meterType:id,name', 'assignedTo', 'requestedBy', 'completedBy'])->find($id);
    }

    #[Computed]
    public function editing(): ?WorkOrder
    {
        return $this->findOrder($this->editingId);
    }

    #[Computed]
    public function viewing(): ?WorkOrder
    {
        return $this->findOrder($this->viewingId);
    }

    public function openEdit(int $id): void
    {
        $this->closeModal();
        $this->editingId = (string) $id;
        $order = $this->editing;

        if ($order === null) {
            $this->closeModal();
            $this->flash('That work order no longer exists. The table has been refreshed.', 'error');

            return;
        }

        foreach (self::FIELDS as $field => $column) {
            $value = $order->{$column};
            $this->edit[$field] = match (true) {
                $value instanceof WorkOrderType => $value->value,
                $value === null => '',
                default => (string) $value,
            };
        }
        $this->edit['status'] = $order->status->value;
    }

    public function openView(int $id): void
    {
        $this->closeModal();
        $this->viewingId = (string) $id;

        if ($this->viewing === null) {
            $this->closeModal();
            $this->flash('That work order no longer exists. The table has been refreshed.', 'error');
        }
    }

    public function closeModal(): void
    {
        $this->editingId = '';
        $this->viewingId = '';
        unset($this->editing, $this->viewing);
        $this->edit = [...self::emptyValues(), 'status' => ''];
        $this->notice = null;
        $this->resetValidation(array_map(fn (string $field): string => "edit.{$field}", [...array_keys(self::FIELDS), 'status']));
    }

    /**
     * Save the changes made in the Update Work Order modal. Completing an order stamps who completed it and when;
     * moving it out of Completed clears that again.
     */
    public function update(): void
    {
        $this->notice = null;

        if (! $this->allowed(Access::Edit)) {
            return;
        }

        unset($this->editing);
        $order = $this->editing;
        if ($order === null) {
            $this->closeModal();
            $this->flash('That work order no longer exists. The table has been refreshed.', 'error');

            return;
        }

        $this->resetValidation();
        $checked = $this->checkValues($this->edit, 'edit', $order);
        $errors = $checked['errors'];
        $status = WorkOrderStatus::tryFrom($this->edit['status'] ?? '');
        if ($status === null) {
            $errors['edit.status'] = 'Select a status.';
        }

        if ($errors !== []) {
            $this->showErrors($errors);
            $this->notice = 'Please correct the highlighted fields.';

            return;
        }

        $attributes = [...$checked['attributes'], 'status' => $status];
        if ($status === WorkOrderStatus::Completed && $order->status !== WorkOrderStatus::Completed) {
            $attributes['completed_at'] = now();
            $attributes['completed_by_id'] = $this->user()->id;
        } elseif ($status !== WorkOrderStatus::Completed) {
            $attributes['completed_at'] = null;
            $attributes['completed_by_id'] = null;
        }

        $order->update($attributes);

        $this->closeModal();
        $this->flash("Work order {$order->order_number} updated.");
    }

    /* --------------------------- Default user role --------------------------- */

    /**
     * @return Collection<int, Role>
     */
    #[Computed]
    public function roles(): Collection
    {
        return $this->company()->roles()->orderBy('name')->get(['id', 'name']);
    }

    public function openRoleChooser(): void
    {
        $this->roleChoice = (string) ($this->company()->work_order_assignee_role_id ?? '');
        $this->choosingRole = true;
        $this->resetValidation('roleChoice');
    }

    public function closeRoleChooser(): void
    {
        $this->choosingRole = false;
        $this->resetValidation('roleChoice');
    }

    /**
     * Save the role whose users the Assigned To field lists; none lists every active user.
     */
    public function saveDefaultRole(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $roleId = null;
        if ($this->roleChoice !== '') {
            $roleId = $this->roles->firstWhere('id', self::idFrom($this->roleChoice))?->id;
            if ($roleId === null) {
                $this->addError('roleChoice', 'Select a role from the list.');

                return;
            }
        }

        $company = $this->company();
        $company->update(['work_order_assignee_role_id' => $roleId]);

        // The user already picked may not have the new role.
        if (! array_key_exists((int) $this->values['assignedToId'], $this->assigneeOptions(null))) {
            $this->values['assignedToId'] = '';
        }

        $this->choosingRole = false;
        $this->flash($roleId === null
            ? 'Assigned To now lists every active user.'
            : 'Assigned To now lists users with the '.$this->roles->firstWhere('id', $roleId)->name.' role.');
    }

    /* -------------------------------- Render --------------------------------- */

    public function render(): View
    {
        $company = $this->company();
        $timezone = $company->timezone;
        $search = trim($this->search);

        $matchingTypes = $search === '' ? [] : array_map(
            fn (WorkOrderType $type): string => $type->value,
            array_filter(WorkOrderType::cases(), fn (WorkOrderType $type): bool => str_contains(mb_strtolower($type->label()), mb_strtolower($search))),
        );

        $query = $company->workOrders()
            ->with(['customer:id,account_number,first_name,last_name', 'completedBy:id,name'])
            ->when($this->pendingOnly, fn ($query) => $query->where('status', WorkOrderStatus::InProgress))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('order_number', 'like', "%{$search}%")
                ->orWhereIn('order_type', $matchingTypes)
                ->orWhereHas('customer', fn ($query) => $query
                    ->where('account_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->orderByDesc('id');

        $overdueCount = $company->workOrders()
            ->where('status', WorkOrderStatus::InProgress)
            ->where('requested_at', '<', WorkOrder::overdueCutoff($timezone))
            ->count();

        $editing = $this->editing;
        $chosenCustomer = $this->chosenCustomer;

        return view('livewire.work-orders.work-orders-page', [
            'orders' => $this->paginateClamped($query, self::PER_PAGE),
            'overdueCount' => $overdueCount,
            'timezone' => $timezone,
            'chosenCustomer' => $chosenCustomer,
            'customerMatches' => $this->customerMatches,
            'orderTypes' => WorkOrderType::options(),
            'meterTypes' => $this->meterTypeOptions($chosenCustomer?->meter_type_id),
            'assignees' => $this->assigneeOptions(null),
            'assigneeRole' => $company->workOrderAssigneeRole,
            'editing' => $editing,
            'viewing' => $this->viewing,
            'editMeterTypes' => $editing ? $this->meterTypeOptions($editing->meter_type_id) : [],
            'editAssignees' => $editing ? $this->assigneeOptions($editing->assigned_to_id) : [],
            'access' => $this->access,
        ]);
    }
}
