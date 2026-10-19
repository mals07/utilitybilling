<?php

namespace App\Livewire\WorkOrders;

use App\Enums\Access;
use App\Enums\FeeBilling;
use App\Enums\Page;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Livewire\Concerns\GuardsPageAccess;
use App\Livewire\Concerns\ParsesIds;
use App\Models\Customer;
use App\Models\MeterType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderFee;
use App\Support\ImageType;
use App\Support\WorkOrderCompletion;
use App\Support\WorkOrderRules;
use DomainException;
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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Work Orders: the Submit Work Order form, the work orders table, and the modals to update,
 * put on hold, cancel, complete and view an order, choose the Assigned To role, and set default fees.
 */
#[Layout('layouts.app')]
#[Title('Work Orders')]
class WorkOrdersPage extends Component
{
    use GuardsPageAccess, ParsesIds, WithFileUploads, WithPagination;

    /**
     * Work orders shown per page of the table.
     */
    public const PER_PAGE = 10;

    /**
     * Minimum rows drawn in the table, so an empty table keeps its shape.
     */
    public const MINIMUM_ROWS = 8;

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
     * Only list open orders (In Progress or On Hold).
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

    /**
     * "hold" or "cancel" while the reason prompt is open over the Update modal.
     */
    public string $reasonFor = '';

    public string $reason = '';

    /* ------------------------------- Complete -------------------------------- */

    /**
     * The order open in the Complete Work Order modal.
     */
    public string $completingId = '';

    /**
     * @var array{fields: array<string, string>, checked: array<int, bool>, completedOn: string, completedById: string, remarks: string, fee: string}
     */
    public array $completion = [];

    /**
     * Photos picked in the Complete modal, not saved yet.
     *
     * @var list<TemporaryUploadedFile>
     */
    public array $photos = [];

    /**
     * The photos just picked, added to $photos once checked.
     *
     * @var list<TemporaryUploadedFile>
     */
    public array $newPhotos = [];

    /* ------------------------ Default role / fees modals ---------------------- */

    public bool $choosingRole = false;

    /**
     * The role picked in the modal; '' lists every active user.
     */
    public string $roleChoice = '';

    public bool $settingFees = false;

    /**
     * @var array<string, string> Keyed by WorkOrderType value.
     */
    public array $fees = [];

    /**
     * How each type's fee is billed, a FeeBilling value keyed by WorkOrderType value.
     *
     * @var array<string, string>
     */
    public array $feeBilling = [];

    protected function page(): Page
    {
        return Page::WorkOrders;
    }

    public function mount(): void
    {
        $this->values = self::emptyValues();
        $this->edit = self::emptyValues();
        $this->completion = self::emptyCompletion();
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

    /**
     * @return array{fields: array<string, string>, checked: array<int, bool>, completedOn: string, completedById: string, remarks: string, fee: string}
     */
    private static function emptyCompletion(): array
    {
        return ['fields' => [], 'checked' => [], 'completedOn' => '', 'completedById' => '', 'remarks' => '', 'fee' => ''];
    }

    public function updated(string $property): void
    {
        foreach (['values.', 'edit.', 'completion.', 'fees.', 'feeBilling.'] as $prefix) {
            if (str_starts_with($property, $prefix)) {
                $this->resetValidation($property);
            }
        }
        if ($property === 'values.orderType') {
            $this->resetValidation('values.orderType');
        }
        if ($property === 'reason') {
            $this->resetValidation('reason');
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

        $query = $this->company()->customers()->select(['id', 'account_number', 'first_name', 'last_name']);
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

        // Start from the customer's current meter.
        $this->values['meterTypeId'] = (string) ($customer->meter_type_id ?? $this->values['meterTypeId']);
        $this->values['meterNumber'] = (string) ($customer->meter_number ?? '');
        $this->values['serialNumber'] = (string) ($customer->meter_serial_number ?? '');

        // A type picked before the customer may not apply to them.
        $type = WorkOrderType::tryFrom($this->values['orderType']);
        if ($type !== null && WorkOrderRules::whyNot($type, $customer) !== null) {
            $this->values['orderType'] = '';
        }

        $this->resetValidation(['customerId', 'values.orderType', 'values.meterTypeId', 'values.meterNumber', 'values.serialNumber']);
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
     * Active users of the company, narrowed to the default role when one is chosen unless
     * $anyRole, plus the user an order is already assigned to.
     *
     * @return array<int, string>
     */
    private function assigneeOptions(?int $keep, bool $anyRole = false): array
    {
        $company = $this->company();
        $roleId = $anyRole ? null : $company->work_order_assignee_role_id;

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

        // Office work has no meter to note.
        if (WorkOrderType::tryFrom($values['orderType'])?->isOfficeWork()) {
            $values['meterTypeId'] = $values['meterNumber'] = $values['serialNumber'] = '';
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
        } elseif (! isset($errors['values.orderType'])) {
            $type = WorkOrderType::from($this->values['orderType']);
            if ($reason = WorkOrderRules::whyNot($type, $customer)) {
                $errors['values.orderType'] = "{$type->label()} can't be submitted: {$reason}.";
            }
        }

        if ($errors !== []) {
            $this->showErrors($errors);

            return;
        }

        try {
            $order = WorkOrder::createNumbered($this->company(), [
                ...$checked['attributes'],
                'customer_id' => $customer->id,
                'status' => WorkOrderStatus::InProgress,
                'requested_by_id' => $this->user()->id,
                'requested_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->flash("The work order couldn't be submitted. Please try again.", 'error');

            return;
        }

        $order->log($this->user(), 'submitted', 'Submitted and assigned to '.($order->assignedTo?->name ?? 'nobody').'.');

        $this->resetForm();
        $this->resetPage();
        $this->flash("Work order {$order->order_number} submitted.");
    }

    private function resetForm(): void
    {
        $this->reset('customerSearch', 'customerId');
        unset($this->chosenCustomer, $this->customerMatches);
        $this->values = self::emptyValues();
        $this->resetValidation();
    }

    /* ----------------------------- Finding orders ---------------------------- */

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

    #[Computed]
    public function completing(): ?WorkOrder
    {
        return $this->findOrder($this->completingId);
    }

    private function gone(): void
    {
        $this->closeModal();
        $this->flash('That work order no longer exists. The table has been refreshed.', 'error');
    }

    public function closeModal(): void
    {
        $this->editingId = '';
        $this->viewingId = '';
        $this->completingId = '';
        unset($this->editing, $this->viewing, $this->completing);
        $this->edit = self::emptyValues();
        $this->completion = self::emptyCompletion();
        $this->reset('notice', 'reasonFor', 'reason', 'photos', 'newPhotos');
        $this->resetValidation();
    }

    /* ---------------------------- Update work order --------------------------- */

    /**
     * Opens an open order in the Update modal; a completed or cancelled one opens in the View modal.
     */
    public function openEdit(int $id): void
    {
        $this->closeModal();
        $this->editingId = (string) $id;
        $order = $this->editing;

        if ($order === null) {
            $this->gone();

            return;
        }
        if (! $order->status->isOpen()) {
            $this->openView($id);

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
    }

    /**
     * The open order in the Update modal, after checking the user may change it.
     */
    private function editableOrder(): ?WorkOrder
    {
        $this->notice = null;

        if (! $this->allowed(Access::Edit)) {
            return null;
        }

        unset($this->editing);
        $order = $this->editing;
        if ($order === null) {
            $this->gone();

            return null;
        }
        if (! $order->status->isOpen()) {
            $this->closeModal();
            $this->flash("Work order {$order->order_number} is already {$order->status->label()}.", 'error');

            return null;
        }

        return $order;
    }

    /**
     * Save the changes made in the Update Work Order modal. The order type can't change.
     */
    public function update(): void
    {
        $order = $this->editableOrder();
        if ($order === null) {
            return;
        }

        $this->resetValidation();
        $this->edit['orderType'] = $order->order_type->value;
        $checked = $this->checkValues($this->edit, 'edit', $order);

        if ($checked['errors'] !== []) {
            $this->showErrors($checked['errors']);
            $this->notice = 'Please correct the highlighted fields.';

            return;
        }

        $order->fill($checked['attributes']);
        $changes = $this->describeEdits($order);
        $order->save();

        if ($changes !== []) {
            $order->log($this->user(), 'edited', 'Edited: '.implode('; ', $changes).'.');
        }

        $this->closeModal();
        $this->flash("Work order {$order->order_number} updated.");
    }

    /**
     * "Meter No: A → B" for each field about to change.
     *
     * @return list<string>
     */
    private function describeEdits(WorkOrder $order): array
    {
        $labels = ['meter_type_id' => 'Meter Type', 'meter_number' => 'Meter No', 'serial_number' => 'Serial No', 'assigned_to_id' => 'Assigned To', 'description' => 'Description'];

        $changes = [];
        foreach ($labels as $column => $label) {
            if (! $order->isDirty($column)) {
                continue;
            }
            if ($column === 'description') {
                $changes[] = 'description changed';

                continue;
            }
            $show = fn (mixed $value): string => match (true) {
                $value === null || $value === '' => '(none)',
                $column === 'meter_type_id' => MeterType::withTrashed()->find($value)?->name ?? '(none)',
                $column === 'assigned_to_id' => User::query()->find($value)?->name ?? '(none)',
                default => (string) $value,
            };
            $changes[] = "{$label}: {$show($order->getOriginal($column))} → {$show($order->getAttribute($column))}";
        }

        return $changes;
    }

    /* -------------------------- Hold / resume / cancel ------------------------ */

    public function askReason(string $for): void
    {
        if (in_array($for, ['hold', 'cancel'], true)) {
            $this->reasonFor = $for;
            $this->reason = '';
            $this->resetValidation('reason');
        }
    }

    public function closeReason(): void
    {
        $this->reset('reasonFor', 'reason');
        $this->resetValidation('reason');
    }

    /**
     * Put the order on hold or cancel it, with the reason given.
     */
    public function confirmReason(): void
    {
        $reason = trim($this->reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            $this->addError('reason', $reason === '' ? 'Give a reason.' : 'The reason must not be greater than 255 characters.');

            return;
        }

        $order = $this->editableOrder();
        if ($order === null) {
            return;
        }

        if ($this->reasonFor === 'hold') {
            if ($order->status === WorkOrderStatus::OnHold) {
                $this->closeReason();

                return;
            }
            $order->update(['status' => WorkOrderStatus::OnHold, 'hold_reason' => $reason]);
            $order->log($this->user(), 'on_hold', "Put on hold: {$reason}");
            $message = "Work order {$order->order_number} is on hold.";
        } elseif ($this->reasonFor === 'cancel') {
            $order->update(['status' => WorkOrderStatus::Cancelled, 'hold_reason' => null, 'cancel_reason' => $reason]);
            $order->log($this->user(), 'cancelled', "Cancelled: {$reason}");
            $message = "Work order {$order->order_number} was cancelled.";
        } else {
            return;
        }

        $this->closeModal();
        $this->flash($message);
    }

    public function resume(): void
    {
        $order = $this->editableOrder();
        if ($order === null || $order->status !== WorkOrderStatus::OnHold) {
            return;
        }

        $order->update(['status' => WorkOrderStatus::InProgress, 'hold_reason' => null]);
        $order->log($this->user(), 'resumed', 'Resumed.');

        $this->closeModal();
        $this->flash("Work order {$order->order_number} resumed.");
    }

    /* -------------------------------- Complete -------------------------------- */

    public function openComplete(int $id): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $this->closeModal();
        $this->completingId = (string) $id;
        $order = $this->completing;

        if ($order === null) {
            $this->gone();

            return;
        }
        if (! $order->status->isOpen()) {
            $this->openView($id);

            return;
        }

        $fee = $this->company()->workOrderFees()->where('order_type', $order->order_type)->value('amount');
        $customer = $order->customer;
        $fields = array_fill_keys(array_keys($order->order_type->completionFields()), '');

        // Prefill what is already known.
        $prefill = [
            'meter_number' => $order->meter_number,
            'serial_number' => $order->serial_number,
            'meter_type_id' => $order->meter_type_id,
            'new_meter_type_id' => $order->order_type === WorkOrderType::MeterReplacement ? null : $order->meter_type_id,
        ];
        foreach ($prefill as $key => $value) {
            if (array_key_exists($key, $fields) && $value !== null) {
                $fields[$key] = (string) $value;
            }
        }

        $this->completion = [
            'fields' => $fields,
            'checked' => [],
            'completedOn' => $this->company()->today(),
            'completedById' => (string) ($order->assigned_to_id ?? $this->user()->id),
            'remarks' => '',
            'fee' => $fee === null ? '' : (string) $fee,
        ];

        // A changed customer may no longer allow this; say so before the form is filled.
        if ($reason = WorkOrderRules::whyNot($order->order_type, $customer, $order->id)) {
            $this->addError('completion', "This order can't be completed: {$reason}.");
        }
    }

    public function updatedNewPhotos(): void
    {
        $this->resetValidation('photos');

        foreach ($this->newPhotos as $file) {
            if (count($this->photos) >= WorkOrderCompletion::MAX_PHOTOS) {
                $this->addError('photos', 'Add no more than '.WorkOrderCompletion::MAX_PHOTOS.' photos.');
                break;
            }
            if ($error = self::photoError($file)) {
                $this->addError('photos', $error);

                continue;
            }
            $this->photos[] = $file;
        }

        $this->newPhotos = [];
    }

    private static function photoError(TemporaryUploadedFile $file): ?string
    {
        $name = $file->getClientOriginalName();

        if (! in_array($file->getMimeType(), WorkOrderCompletion::PHOTO_MIME_TYPES, true) || $file->getSize() === 0) {
            return "\"{$name}\" is not a JPG, PNG or WebP image.";
        }
        if ($file->getSize() > WorkOrderCompletion::PHOTO_MAX_BYTES) {
            return "\"{$name}\" is larger than 2MB.";
        }

        $detected = ImageType::detect((string) file_get_contents($file->getRealPath()));
        if ($detected === null || ! in_array($detected, WorkOrderCompletion::PHOTO_MIME_TYPES, true)) {
            return "\"{$name}\" is not a valid JPG, PNG or WebP image.";
        }

        return null;
    }

    public function removePhoto(int $index): void
    {
        array_splice($this->photos, $index, 1);
        $this->resetValidation('photos');
    }

    public function complete(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        unset($this->completing);
        $order = $this->completing;
        if ($order === null) {
            $this->gone();

            return;
        }
        if (! $order->status->isOpen()) {
            $this->closeModal();
            $this->flash("Work order {$order->order_number} is already {$order->status->label()}.", 'error');

            return;
        }

        $this->resetValidation();
        $photos = array_values(array_filter($this->photos, fn ($file): bool => $file instanceof TemporaryUploadedFile && self::photoError($file) === null));
        $result = WorkOrderCompletion::check(
            $order,
            $this->completion,
            count($order->order_type->allowsPhotos() ? $photos : []),
            $this->assigneeOptions($order->assigned_to_id, anyRole: true),
            $this->company()->timezone,
        );

        if ($result['errors'] !== []) {
            $this->showErrors($result['errors']);

            return;
        }

        $files = $order->order_type->allowsPhotos() ? array_map(function (TemporaryUploadedFile $file): array {
            $data = (string) file_get_contents($file->getRealPath());

            return ['data' => $data, 'mime' => (string) ImageType::detect($data), 'name' => $file->getClientOriginalName()];
        }, $photos) : [];

        try {
            $notes = WorkOrderCompletion::complete($order, $result['data'], $this->user(), $files, $this->company()->timezone);
        } catch (DomainException $exception) {
            $this->addError('completion', $exception->getMessage());

            return;
        }

        $this->closeModal();
        $this->flash(trim("Work order {$order->order_number} completed. ".implode(' ', $notes)));
    }

    /* ----------------------------- View work order ---------------------------- */

    public function openView(int $id): void
    {
        $this->closeModal();
        $this->viewingId = (string) $id;

        if ($this->viewing === null) {
            $this->gone();
        }
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

    /* ------------------------------ Default fees ----------------------------- */

    public function openFees(): void
    {
        $saved = $this->company()->workOrderFees()->get()->keyBy(fn (WorkOrderFee $fee): string => $fee->order_type->value);

        $this->fees = [];
        $this->feeBilling = [];
        foreach (WorkOrderType::cases() as $type) {
            $row = $saved[$type->value] ?? null;
            $this->fees[$type->value] = $row?->amount === null ? '' : (string) $row->amount;
            $this->feeBilling[$type->value] = ($row?->billing ?? FeeBilling::defaultFor($type))->value;
        }
        $this->settingFees = true;
        $this->resetValidation();
    }

    public function closeFees(): void
    {
        $this->settingFees = false;
        $this->resetValidation();
    }

    /**
     * Save the fee prefilled when completing each order type (a blank fee prefills nothing) and
     * whether it is billed on its own invoice or on the next monthly bill.
     */
    public function saveFees(): void
    {
        if (! $this->allowed(Access::Edit)) {
            return;
        }

        $this->resetValidation();
        $errors = [];
        foreach (WorkOrderType::cases() as $type) {
            $amount = trim((string) ($this->fees[$type->value] ?? ''));
            if ($amount !== '' && ! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount)) {
                $errors["fees.{$type->value}"] = 'Enter an amount such as 150 or 150.50.';
            }
            if (FeeBilling::tryFrom((string) ($this->feeBilling[$type->value] ?? '')) === null) {
                $errors["feeBilling.{$type->value}"] = 'Choose how this fee is billed.';
            }
        }
        if ($errors !== []) {
            $this->showErrors($errors);

            return;
        }

        $company = $this->company();
        foreach (WorkOrderType::cases() as $type) {
            $amount = trim((string) ($this->fees[$type->value] ?? ''));
            $billing = FeeBilling::from($this->feeBilling[$type->value]);
            if ($amount === '' && $billing === FeeBilling::defaultFor($type)) {
                $company->workOrderFees()->where('order_type', $type)->delete();
            } else {
                $company->workOrderFees()->updateOrCreate(
                    ['order_type' => $type],
                    ['amount' => $amount === '' ? null : $amount, 'billing' => $billing],
                );
            }
        }

        $this->settingFees = false;
        $this->flash('Default fees saved.');
    }

    /* -------------------------------- Render --------------------------------- */

    /**
     * The completion form's fields and checklist for the order, given the answers so far.
     *
     * @return array{fields: array<string, array<string, mixed>>, checklist: array<int, string>, meterTypes: array<int, string>, completedBy: array<int, string>}
     */
    private function completionForm(WorkOrder $order): array
    {
        $answers = $this->completion['fields'] ?? [];

        return [
            'fields' => WorkOrderCompletion::visibleFields($order->order_type, $answers, $order->customer),
            'checklist' => WorkOrderCompletion::visibleChecklist($order->order_type, $answers),
            'meterTypes' => $this->meterTypeOptions(null),
            'completedBy' => $this->assigneeOptions($order->assigned_to_id, anyRole: true),
        ];
    }

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
            ->with(['customer:id,account_number,first_name,last_name', 'completedBy:id,name', 'parent:id,order_number'])
            ->when($this->pendingOnly, fn ($query) => $query->whereIn('status', WorkOrderStatus::openValues()))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('order_number', 'like', "%{$search}%")
                ->orWhereIn('order_type', $matchingTypes)
                ->orWhereHas('customer', fn ($query) => $query
                    ->where('account_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->orderByDesc('id');

        $overdueCount = $company->workOrders()
            ->whereIn('status', WorkOrderStatus::openValues())
            ->where('requested_at', '<', WorkOrder::overdueCutoff($timezone))
            ->count();

        $chosenCustomer = $this->chosenCustomer;
        $editing = $this->editing;
        $viewing = $this->viewing?->load([
            'readings', 'photos:id,work_order_id,file_name', 'activities.user:id,name', 'feeInvoice:id,invoice_number',
            'parent:id,order_number,order_type', 'followUps:id,parent_work_order_id,order_number,order_type,status',
        ]);
        $completing = $this->completing;

        return view('livewire.work-orders.work-orders-page', [
            'orders' => $this->paginateClamped($query, self::PER_PAGE),
            'overdueCount' => $overdueCount,
            'timezone' => $timezone,
            'today' => $company->today(),
            'chosenCustomer' => $chosenCustomer,
            'customerMatches' => $this->customerMatches,
            'orderTypes' => WorkOrderType::options(),
            'ineligibleTypes' => $chosenCustomer ? array_filter(WorkOrderRules::eligibility($chosenCustomer)) : [],
            'chosenType' => WorkOrderType::tryFrom($this->values['orderType']),
            'meterTypes' => $this->meterTypeOptions($chosenCustomer?->meter_type_id),
            'assignees' => $this->assigneeOptions(null),
            'assigneeRole' => $company->workOrderAssigneeRole,
            'editing' => $editing,
            'viewing' => $viewing,
            'completing' => $completing,
            'completionForm' => $completing ? $this->completionForm($completing) : null,
            'editMeterTypes' => $editing ? $this->meterTypeOptions($editing->meter_type_id) : [],
            'editAssignees' => $editing ? $this->assigneeOptions($editing->assigned_to_id) : [],
            'access' => $this->access,
        ]);
    }
}
