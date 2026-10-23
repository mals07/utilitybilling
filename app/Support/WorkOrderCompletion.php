<?php

namespace App\Support;

use App\Enums\CustomerStatus;
use App\Enums\ReadingKind;
use App\Enums\WorkOrderFlag;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Livewire\Auth\AuthRules;
use App\Livewire\Company\ProfileForm;
use App\Models\Customer;
use App\Models\MeterType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderReading;
use App\Support\Billing\WorkOrderBilling;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Completing a work order: checks the type's completion form, then records the readings, fee,
 * photos and timeline, updates the customer, and creates any follow-up order, all at once.
 */
final class WorkOrderCompletion
{
    public const MAX_PHOTOS = 3;

    public const PHOTO_MAX_BYTES = 2 * 1024 * 1024;

    public const PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const READING_PATTERN = '/^\d{1,9}(\.\d{1,3})?$/';

    private const AMOUNT_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    /**
     * The fields asked for, given the answers so far and whether the customer has a meter.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, array<string, mixed>>
     */
    public static function visibleFields(WorkOrderType $type, array $answers, Customer $customer): array
    {
        return array_filter(
            $type->completionFields(),
            fn (array $spec): bool => self::applies($spec['when'] ?? [], $answers) && (! ($spec['ifMeter'] ?? false) || $customer->hasMeter()),
        );
    }

    /**
     * The checklist items that apply, keyed by their position in the type's full checklist.
     *
     * @param  array<string, mixed>  $answers
     * @return array<int, string>
     */
    public static function visibleChecklist(WorkOrderType $type, array $answers): array
    {
        $items = [];
        foreach ($type->checklist() as $index => $item) {
            [$label, $when] = is_array($item) ? $item : [$item, []];
            if (self::applies($when, $answers)) {
                $items[$index] = $label;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $when
     * @param  array<string, mixed>  $answers
     */
    private static function applies(array $when, array $answers): bool
    {
        foreach ($when as $field => $value) {
            if ((string) ($answers[$field] ?? '') !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * The completed order's answers as label => readable value, e.g. "Reason" => "Non-payment".
     *
     * @return array<string, string>
     */
    public static function describe(WorkOrder $order): array
    {
        $specs = $order->order_type->completionFields();
        $described = [];

        foreach ($order->completion_data['fields'] ?? [] as $key => $value) {
            $spec = $specs[$key] ?? null;
            if ($spec === null || $value === '' || $value === null) {
                continue;
            }

            $described[$spec['label']] = match ($spec['kind']) {
                'select' => $spec['options'][$value] ?? (string) $value,
                'meter_type' => MeterType::withTrashed()->find($value)?->name ?? (string) $value,
                'date' => Format::isoDate((string) $value),
                'month' => Carbon::createFromFormat('Y-m', (string) $value)->format('F Y'),
                'amount' => Format::amount((string) $value),
                default => (string) $value,
            };
        }

        return $described;
    }

    /**
     * Checks the completion form. Errors are keyed like the form's fields ("completion.fields.reason").
     *
     * @param  array{fields?: array<string, mixed>, checked?: array<int|string, mixed>, completedOn?: string, completedById?: string, remarks?: string, fee?: string}  $input
     * @param  array<int, string>  $completedByOptions  Users who may be recorded as having done the work.
     * @return array{errors: array<string, string>, data: array{fields: array<string, string>, checklist: list<string>, completedOn: string, completedById: int, remarks: ?string, fee: ?string}|null}
     */
    public static function check(WorkOrder $order, array $input, int $photoCount, array $completedByOptions, ?string $timezone): array
    {
        $type = $order->order_type;
        $customer = $order->customer;
        $answers = array_map(fn ($value): string => trim((string) $value), $input['fields'] ?? []);
        $fields = self::visibleFields($type, $answers, $customer);
        $errors = [];

        if ($reason = WorkOrderRules::whyNot($type, $customer, $order->id)) {
            $errors['completion'] = "This order can't be completed: {$reason}.";
        }

        foreach ($fields as $key => $spec) {
            if ($message = self::fieldError($spec, $answers[$key] ?? '', $order->company_id)) {
                $errors["completion.fields.{$key}"] = $message;
            }
        }
        $errors = [...$errors, ...self::crossCheck($type, $answers, $fields, $customer)];

        $checked = $input['checked'] ?? [];
        foreach (array_keys(self::visibleChecklist($type, $answers)) as $index) {
            if (empty($checked[$index])) {
                $errors['completion.checked'] = 'Tick every checklist item to confirm the work is done.';
                break;
            }
        }

        $today = Format::todayIn($timezone);
        $requestedOn = $order->requested_at->copy()->setTimezone($timezone ?? Format::DEFAULT_TIMEZONE)->toDateString();
        $completedOn = trim((string) ($input['completedOn'] ?? ''));
        $errors += match (true) {
            ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $completedOn) || ! strtotime($completedOn) => ['completion.completedOn' => 'Enter the date the work was completed.'],
            $completedOn > $today => ['completion.completedOn' => "The completion date can't be in the future."],
            $completedOn < $requestedOn => ['completion.completedOn' => "The completion date can't be before the order was requested (".Format::isoDate($requestedOn).').'],
            default => [],
        };

        $completedById = (int) ($input['completedById'] ?? 0);
        if (! array_key_exists($completedById, $completedByOptions)) {
            $errors['completion.completedById'] = 'Select who did the work.';
        }

        $remarks = trim((string) ($input['remarks'] ?? ''));
        if (mb_strlen($remarks) > 2000) {
            $errors['completion.remarks'] = 'The remarks must not be greater than 2000 characters.';
        }

        $fee = trim((string) ($input['fee'] ?? ''));
        if ($fee !== '' && ! preg_match(self::AMOUNT_PATTERN, $fee)) {
            $errors['completion.fee'] = 'Enter an amount such as 150 or 150.50.';
        }

        if ($type->requiresPhoto() && $photoCount === 0) {
            $errors['photos'] = "Add at least one photo as proof of the {$type->label()}.";
        } elseif ($photoCount > self::MAX_PHOTOS) {
            $errors['photos'] = 'Add no more than '.self::MAX_PHOTOS.' photos.';
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'data' => null];
        }

        return ['errors' => [], 'data' => [
            'fields' => array_intersect_key($answers, $fields),
            'checklist' => array_values(self::visibleChecklist($type, $answers)),
            'completedOn' => $completedOn,
            'completedById' => $completedById,
            'remarks' => $remarks === '' ? null : $remarks,
            'fee' => $fee === '' ? null : $fee,
        ]];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private static function fieldError(array $spec, string $value, int $companyId): ?string
    {
        $label = $spec['label'];

        if ($value === '') {
            return $spec['required'] ? ($spec['kind'] === 'select' || $spec['kind'] === 'meter_type' ? "Select the {$label}." : "The {$label} field is required.") : null;
        }

        return match ($spec['kind']) {
            'text' => mb_strlen($value) > ($spec['max'] ?? 100) ? "The {$label} must not be greater than ".($spec['max'] ?? 100).' characters.' : null,
            'textarea' => mb_strlen($value) > 2000 ? "The {$label} must not be greater than 2000 characters." : null,
            'reading', 'decimal' => preg_match(self::READING_PATTERN, $value) ? null : 'Enter a number such as 1234 or 1234.5.',
            'amount' => preg_match(self::AMOUNT_PATTERN, $value) ? null : 'Enter an amount such as 150 or 150.50.',
            'latitude' => is_numeric($value) && abs((float) $value) <= 90 ? null : 'Enter a latitude between -90 and 90.',
            'longitude' => is_numeric($value) && abs((float) $value) <= 180 ? null : 'Enter a longitude between -180 and 180.',
            'select' => array_key_exists($value, $spec['options']) ? null : "Select the {$label} from the list.",
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? null : 'Enter a valid date.',
            'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? null : 'Enter a valid month.',
            'meter_type' => MeterType::query()->where('company_id', $companyId)->where('is_active', true)->whereKey((int) $value)->exists()
                ? null : 'Select a meter type from the list.',
            'email' => mb_strlen($value) <= ($spec['max'] ?? 150) && preg_match(AuthRules::EMAIL_PATTERN, $value) ? null : 'Enter a valid email address.',
            'phone' => mb_strlen($value) <= ($spec['max'] ?? 30) && preg_match(ProfileForm::PHONE_PATTERN, $value) ? null : 'Enter a valid phone number.',
            default => null,
        };
    }

    /**
     * Checks that involve more than one answer.
     *
     * @param  array<string, string>  $answers
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private static function crossCheck(WorkOrderType $type, array $answers, array $fields, Customer $customer): array
    {
        $errors = [];
        $number = fn (string $key): ?float => isset($fields[$key]) && preg_match(self::READING_PATTERN, $answers[$key] ?? '') ? (float) $answers[$key] : null;

        // Meters only count up.
        $before = $number('reading_before');
        $after = $number('reading_after');
        if ($before !== null && $after !== null && $after < $before) {
            $errors['completion.fields.reading_after'] = "The reading after can't be lower than the reading before.";
        }

        if (isset($fields['latitude']) && (($answers['latitude'] ?? '') === '') !== (($answers['longitude'] ?? '') === '')) {
            $errors['completion.fields.longitude'] = 'Enter both the latitude and the longitude, or neither.';
        }

        if ($type === WorkOrderType::MeterReplacement && ($answers['new_meter_number'] ?? '') !== ''
            && mb_strtolower($answers['new_meter_number']) === mb_strtolower((string) $customer->meter_number)) {
            $errors['completion.fields.new_meter_number'] = 'The new meter no. is the same as the current one.';
        }

        if ($type === WorkOrderType::BillingComplaint && ($answers['outcome'] ?? '') === 'reread_needed' && ! $customer->hasMeter()) {
            $errors['completion.fields.outcome'] = 'This customer has no meter to re-read. Choose another outcome.';
        }

        return $errors;
    }

    /**
     * Completes the order with checked data. Returns notes worth showing, e.g. follow-ups created.
     *
     * @param  array{fields: array<string, string>, checklist: list<string>, completedOn: string, completedById: int, remarks: ?string, fee: ?string}  $data
     * @param  list<array{data: string, mime: string, name: string}>  $photos
     * @return list<string>
     *
     * @throws DomainException When the order was closed or the customer changed in the meantime.
     */
    public static function complete(WorkOrder $order, array $data, User $actor, array $photos, ?string $timezone): array
    {
        return DB::transaction(function () use ($order, $data, $actor, $photos, $timezone): array {
            $order = WorkOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! $order->status->isOpen()) {
                throw new DomainException("Work order {$order->order_number} is already {$order->status->label()}.");
            }

            $customer = Customer::query()->lockForUpdate()->findOrFail($order->customer_id);
            if ($reason = WorkOrderRules::whyNot($order->order_type, $customer, $order->id)) {
                throw new DomainException("This order can't be completed: {$reason}.");
            }
            if ($order->order_type === WorkOrderType::Disconnection && ($reason = Disconnections::whyNotComplete($order, $customer))) {
                throw new DomainException("This order can't be completed: {$reason}.");
            }

            $type = $order->order_type;
            $fields = $data['fields'];
            $notes = [];
            $flag = null;
            $oldMeterNumber = $customer->meter_number;

            self::updateCustomer($type, $fields, $customer, $data['completedOn']);
            $customerChanges = self::describeChanges($customer);
            $customer->save();

            // Readings, against the meter that was read.
            foreach (self::visibleFields($type, $fields, $customer) as $key => $spec) {
                if (! isset($spec['reading']) || ($fields[$key] ?? '') === '') {
                    continue;
                }
                WorkOrderReading::query()->create([
                    'company_id' => $order->company_id,
                    'customer_id' => $customer->id,
                    'work_order_id' => $order->id,
                    'meter_number' => $spec['meter'] === 'new' ? $customer->meter_number : $oldMeterNumber,
                    'kind' => $spec['reading'],
                    'reading' => $fields[$key],
                    'read_on' => $data['completedOn'],
                ]);
            }

            if ($type === WorkOrderType::Reconnection && ($mismatch = self::reconnectionMismatch($customer, $order, (float) $fields['reconnection_reading']))) {
                $flag = WorkOrderFlag::ReadingMismatch;
                $notes[] = $mismatch;
            }

            $flag ??= match (true) {
                $type === WorkOrderType::MeterReadingVerification && $fields['outcome'] === 'wrong' => WorkOrderFlag::BillingAdjustment,
                $type === WorkOrderType::BillingComplaint && $fields['outcome'] === 'adjustment_needed' => WorkOrderFlag::BillingAdjustment,
                $type === WorkOrderType::LeakRepair && $fields['leak_location'] === 'after_meter' => WorkOrderFlag::LeakAdjustment,
                $type === WorkOrderType::WaterQualityComplaint && $fields['result'] === 'fail' => WorkOrderFlag::QualityFail,
                default => null,
            };

            $completedOn = $data['completedOn'];
            $completedAt = $completedOn === Format::todayIn($timezone)
                ? now()
                : Carbon::parse("{$completedOn} 12:00", $timezone ?? Format::DEFAULT_TIMEZONE)->utc();

            $order->update([
                'status' => WorkOrderStatus::Completed,
                'hold_reason' => null,
                'completed_at' => $completedAt,
                'completed_by_id' => $data['completedById'],
                'completion_data' => ['fields' => $fields, 'checklist' => $data['checklist'], 'remarks' => $data['remarks']],
                'review_flag' => $flag,
                'fee_amount' => $data['fee'],
            ]);

            foreach ($photos as $photo) {
                $order->photos()->create([
                    'photo_data' => $photo['data'],
                    'mime_type' => $photo['mime'],
                    'file_name' => mb_substr($photo['name'], 0, 255),
                    'uploaded_by_id' => $actor->id,
                ]);
            }

            $completedBy = User::query()->find($data['completedById']);
            $description = "Completed by {$completedBy?->name} on ".Format::isoDate($completedOn).'.';
            if ($customerChanges !== []) {
                $description .= ' Customer updated: '.implode('; ', $customerChanges).'.';
            }
            if ($flag !== null) {
                $description .= " Flagged: {$flag->label()}.";
            }
            // Bill the fee: its own invoice now, or a line on the next monthly bill.
            $feeInvoice = WorkOrderBilling::onCompleted($order->fresh(), $actor, $completedOn);
            if ($data['fee'] !== null && Money::toCents($data['fee']) > 0) {
                $description .= ' Fee: '.Format::amount($data['fee']).($feeInvoice
                    ? " (billed on invoice {$feeInvoice->invoice_number})."
                    : ' (goes on the next monthly bill).');
                $notes[] = $feeInvoice
                    ? 'Fee billed on invoice '.$feeInvoice->invoice_number.', due '.Format::longDate($feeInvoice->due_date).'.'
                    : 'The fee will be added to the next monthly bill.';
            }
            if ($photos !== []) {
                $description .= ' '.count($photos).' photo'.(count($photos) === 1 ? '' : 's').' added.';
            }
            $order->log($actor, 'completed', $description);
            if ($type === WorkOrderType::Disconnection) {
                Disconnections::workOrderCompleted($order, $actor);
            }

            if ($followUp = self::followUp($type, $fields)) {
                $notes[] = self::createFollowUp($order, $customer, $followUp[0], $followUp[1], $actor);
            }

            return $notes;
        });
    }

    /**
     * What completing the type does to the customer record (not saved yet).
     *
     * @param  array<string, string>  $fields
     */
    private static function updateCustomer(WorkOrderType $type, array $fields, Customer $customer, string $completedOn): void
    {
        $nullable = fn (string $key): ?string => ($fields[$key] ?? '') === '' ? null : $fields[$key];

        match ($type) {
            WorkOrderType::NewInstallation => $customer->fill([
                'status' => CustomerStatus::Connected,
                'meter_number' => $fields['meter_number'],
                'meter_serial_number' => $fields['serial_number'],
                'meter_type_id' => (int) $fields['meter_type_id'],
                'meter_installed_on' => $completedOn,
            ]),
            WorkOrderType::Disconnection => $customer->fill(['status' => CustomerStatus::Disconnected]),
            WorkOrderType::Reconnection => $customer->fill(['status' => CustomerStatus::Connected]),
            WorkOrderType::MeterReplacement => $customer->fill([
                'meter_number' => $fields['new_meter_number'],
                'meter_serial_number' => $fields['new_serial_number'],
                'meter_type_id' => (int) $fields['new_meter_type_id'],
                'meter_installed_on' => $completedOn,
            ]),
            WorkOrderType::MeterRelocation => ($fields['latitude'] ?? '') === '' ? null : $customer->fill([
                'latitude' => round((float) $fields['latitude'], 6),
                'longitude' => round((float) $fields['longitude'], 6),
            ]),
            WorkOrderType::ChangeOfOwnership => $customer->fill([
                'first_name' => $fields['new_first_name'],
                'last_name' => $fields['new_last_name'],
                'company_name' => $nullable('new_company_name'),
                'email' => $nullable('new_email'),
                'phone_number' => $nullable('new_phone'),
            ]),
            default => null,
        };
    }

    /**
     * "status: Pending → Connected" for each customer field about to change.
     *
     * @return list<string>
     */
    private static function describeChanges(Customer $customer): array
    {
        $labels = [
            'status' => 'status', 'meter_number' => 'meter no.', 'meter_serial_number' => 'serial no.', 'meter_type_id' => 'meter type',
            'first_name' => 'first name', 'last_name' => 'last name', 'company_name' => 'company', 'email' => 'email',
            'phone_number' => 'phone', 'latitude' => 'latitude', 'longitude' => 'longitude',
        ];

        $changes = [];
        foreach ($labels as $column => $label) {
            if (! $customer->isDirty($column)) {
                continue;
            }
            $show = function (mixed $value) use ($column): string {
                return match (true) {
                    $value === null || $value === '' => '(none)',
                    $column === 'status' => CustomerStatus::from($value instanceof CustomerStatus ? $value->value : (string) $value)->label(),
                    $column === 'meter_type_id' => MeterType::withTrashed()->find($value)?->name ?? '(none)',
                    default => (string) $value,
                };
            };
            $changes[] = "{$label}: {$show($customer->getOriginal($column))} → {$show($customer->getAttribute($column))}";
        }

        return $changes;
    }

    /**
     * Compares the reading at reconnection with the final reading taken at the last disconnection.
     */
    private static function reconnectionMismatch(Customer $customer, WorkOrder $order, float $reading): ?string
    {
        $final = WorkOrderReading::query()
            ->where('customer_id', $customer->id)
            ->where('kind', ReadingKind::Final)
            ->whereHas('workOrder', fn ($query) => $query->where('order_type', WorkOrderType::Disconnection))
            ->latest('read_on')
            ->latest('id')
            ->first();

        if ($final === null || abs((float) $final->reading - $reading) < 0.0005) {
            return null;
        }

        return "The reading at reconnection ({$reading}) differs from the final reading at disconnection ({$final->reading}). Check the meter for tampering.";
    }

    /**
     * The follow-up order an outcome calls for, with why.
     *
     * @param  array<string, string>  $fields
     * @return array{0: WorkOrderType, 1: string}|null
     */
    private static function followUp(WorkOrderType $type, array $fields): ?array
    {
        return match (true) {
            $type === WorkOrderType::MeterRepair && $fields['outcome'] === 'not_repairable' => [WorkOrderType::MeterReplacement, 'Meter found not repairable.'],
            $type === WorkOrderType::MeterReadingVerification && $fields['outcome'] === 'meter_faulty' => [WorkOrderType::MeterRepair, 'Meter found faulty during re-reading.'],
            $type === WorkOrderType::LowWaterPressure && $fields['resolved'] === 'no' => [WorkOrderType::from($fields['follow_up']), "Low pressure not resolved. Cause found: {$fields['cause']}."],
            $type === WorkOrderType::BillingComplaint && $fields['outcome'] === 'reread_needed' => [WorkOrderType::MeterReadingVerification, 'Re-read requested by a billing complaint.'],
            default => null,
        };
    }

    /**
     * Creates the follow-up order, assigned to the same user, unless one of that type can't be opened.
     */
    private static function createFollowUp(WorkOrder $parent, Customer $customer, WorkOrderType $type, string $why, User $actor): string
    {
        if ($reason = WorkOrderRules::whyNot($type, $customer)) {
            $note = "No {$type->label()} follow-up was created: {$reason}.";
            $parent->log($actor, 'follow_up', $note);

            return $note;
        }

        $followUp = WorkOrder::createNumbered($parent->company, [
            'customer_id' => $customer->id,
            'parent_work_order_id' => $parent->id,
            'order_type' => $type,
            'meter_type_id' => $customer->meter_type_id,
            'meter_number' => $customer->meter_number,
            'serial_number' => $customer->meter_serial_number,
            'description' => "Follow-up of work order {$parent->order_number}. {$why}",
            'assigned_to_id' => $parent->assigned_to_id,
            'status' => WorkOrderStatus::InProgress,
            'requested_by_id' => $actor->id,
            'requested_at' => now(),
        ]);

        $followUp->log($actor, 'submitted', "Created as a follow-up of work order {$parent->order_number}. {$why}");
        $parent->log($actor, 'follow_up', "Follow-up work order {$followUp->order_number} ({$type->label()}) created.");

        return "Follow-up work order {$followUp->order_number} ({$type->label()}) was created.";
    }
}
