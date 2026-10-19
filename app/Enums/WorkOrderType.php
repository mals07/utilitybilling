<?php

namespace App\Enums;

/**
 * Kinds of field work a water utility sends staff out for, in the order shown in the Order Type list.
 *
 * Each type also describes its completion form: the fields asked for, the checklist that must be
 * ticked, and whether a photo is required. What completing a type does is in App\Support\WorkOrderCompletion.
 */
enum WorkOrderType: string
{
    case NewInstallation = 'new_installation';
    case Reconnection = 'reconnection';
    case Disconnection = 'disconnection';
    case MeterReplacement = 'meter_replacement';
    case MeterRepair = 'meter_repair';
    case MeterRelocation = 'meter_relocation';
    case MeterReadingVerification = 'meter_reading_verification';
    case LeakRepair = 'leak_repair';
    case PipeRepair = 'pipe_repair';
    case LowWaterPressure = 'low_water_pressure';
    case WaterQualityComplaint = 'water_quality_complaint';
    case BillingComplaint = 'billing_complaint';
    case ChangeOfOwnership = 'change_of_ownership';

    public function label(): string
    {
        return match ($this) {
            self::NewInstallation => 'New Installation',
            self::Reconnection => 'Reconnection',
            self::Disconnection => 'Disconnection',
            self::MeterReplacement => 'Meter Replacement',
            self::MeterRepair => 'Meter Repair',
            self::MeterRelocation => 'Meter Relocation',
            self::MeterReadingVerification => 'Meter Re-reading / Verification',
            self::LeakRepair => 'Leak Repair',
            self::PipeRepair => 'Pipe Repair',
            self::LowWaterPressure => 'Low Water Pressure',
            self::WaterQualityComplaint => 'Water Quality Complaint',
            self::BillingComplaint => 'Billing Complaint',
            self::ChangeOfOwnership => 'Change of Ownership',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])->all();
    }

    /**
     * Work on an existing meter, so the customer must have one.
     */
    public function needsMeter(): bool
    {
        return in_array($this, [self::MeterReplacement, self::MeterRepair, self::MeterRelocation, self::MeterReadingVerification], true);
    }

    /**
     * Office work: the Meter No and Serial No fields don't apply.
     */
    public function isOfficeWork(): bool
    {
        return $this === self::BillingComplaint;
    }

    public function requiresPhoto(): bool
    {
        return in_array($this, [self::NewInstallation, self::Disconnection, self::Reconnection, self::MeterReplacement], true);
    }

    public function allowsPhotos(): bool
    {
        return ! $this->isOfficeWork();
    }

    /**
     * The type-specific fields of the completion form.
     *
     * Each field: label; kind (text, textarea, reading, decimal, amount, latitude, longitude, select, date,
     * month, meter_type, email, phone); required; max length for text; options for selects; "when" => [field => value] to only ask when another answer matches;
     * "ifMeter" to only ask when the customer has a meter; "reading" => ReadingKind and "meter"
     * ("current" or "new") for readings that are recorded.
     *
     * @return array<string, array{label: string, kind: string, required: bool, max?: int, options?: array<string, string>, when?: array<string, string>, ifMeter?: bool, reading?: ReadingKind, meter?: string, hint?: string}>
     */
    public function completionFields(): array
    {
        $yesNo = ['yes' => 'Yes', 'no' => 'No'];

        return match ($this) {
            self::NewInstallation => [
                'meter_number' => self::field('Meter No', 'text', max: 50),
                'serial_number' => self::field('Serial No', 'text', max: 50),
                'meter_type_id' => self::field('Meter Type', 'meter_type'),
                'initial_reading' => self::reading('Initial reading', ReadingKind::Initial, 'new'),
            ],
            self::Disconnection => [
                'reason' => self::field('Reason', 'select', options: [
                    'non_payment' => 'Non-payment',
                    'customer_request' => 'Customer request',
                    'illegal_connection' => 'Illegal connection',
                    'vacant_property' => 'Vacant property',
                    'other' => 'Other',
                ]),
                'final_reading' => self::reading('Final reading', ReadingKind::Final, 'current'),
                'seal_number' => self::field('Seal / lock no.', 'text', max: 50),
            ],
            self::Reconnection => [
                'reconnection_reading' => self::reading('Reading at reconnection', ReadingKind::Reconnection, 'current'),
                'seal_removed' => self::field('Seal no. removed', 'text', required: false, max: 50),
            ],
            self::MeterReplacement => [
                'reason' => self::field('Reason', 'select', options: [
                    'defective' => 'Defective',
                    'stuck' => 'Stuck',
                    'tampered' => 'Tampered',
                    'damaged' => 'Damaged',
                    'end_of_life' => 'End of life',
                    'upgrade' => 'Upgrade',
                ]),
                'old_final_reading' => self::reading('Old meter final reading', ReadingKind::Final, 'current'),
                'new_meter_number' => self::field('New meter no.', 'text', max: 50),
                'new_serial_number' => self::field('New serial no.', 'text', max: 50),
                'new_meter_type_id' => self::field('New meter type', 'meter_type'),
                'new_initial_reading' => self::reading('New meter initial reading', ReadingKind::Initial, 'new'),
            ],
            self::MeterRepair => [
                'findings' => self::field('Findings', 'textarea'),
                'action_taken' => self::field('Action taken', 'textarea'),
                'outcome' => self::field('Outcome', 'select', options: [
                    'repaired' => 'Repaired',
                    'not_repairable' => 'Not repairable (creates a Meter Replacement)',
                ]),
                'reading_before' => self::reading('Reading before', ReadingKind::Before, 'current'),
                'reading_after' => self::reading('Reading after', ReadingKind::After, 'current') + ['when' => ['outcome' => 'repaired']],
            ],
            self::MeterRelocation => [
                'new_location' => self::field('New location', 'textarea', hint: 'Where the meter is now, e.g. "Left of the front gate".'),
                'latitude' => self::field('New map latitude', 'latitude', required: false, hint: 'Optional. Updates the customer\'s map pin with the longitude.'),
                'longitude' => self::field('New map longitude', 'longitude', required: false),
                'reading_before' => self::reading('Reading before', ReadingKind::Before, 'current'),
                'reading_after' => self::reading('Reading after', ReadingKind::After, 'current'),
            ],
            self::MeterReadingVerification => [
                'disputed_reading' => self::field('Disputed reading', 'reading', hint: 'The reading on the bill being questioned.'),
                'verified_reading' => self::reading('Verified reading', ReadingKind::Verified, 'current'),
                'outcome' => self::field('Outcome', 'select', options: [
                    'correct' => 'Reading correct',
                    'wrong' => 'Reading was wrong (billing adjustment needed)',
                    'meter_faulty' => 'Meter faulty (creates a Meter Repair)',
                ]),
            ],
            self::LeakRepair => [
                'leak_location' => self::field('Leak location', 'select', options: [
                    'before_meter' => 'Before the meter',
                    'after_meter' => 'After the meter (customer side)',
                    'main_line' => 'Main line',
                ]),
                'cause' => self::field('Cause', 'text'),
                'action_taken' => self::field('Action taken', 'textarea'),
                'materials' => self::field('Materials used', 'textarea', required: false),
                'reading_before' => self::reading('Reading before', ReadingKind::Before, 'current') + ['required' => false, 'ifMeter' => true],
                'reading_after' => self::reading('Reading after', ReadingKind::After, 'current') + ['required' => false, 'ifMeter' => true],
            ],
            self::PipeRepair => [
                'pipe_size' => self::field('Pipe size', 'text', hint: 'e.g. 1/2", 3/4", 2"'),
                'length_replaced' => self::field('Length replaced (meters)', 'decimal', required: false),
                'cause' => self::field('Cause', 'text'),
                'materials' => self::field('Materials used', 'textarea', required: false),
            ],
            self::LowWaterPressure => [
                'measured_pressure' => self::field('Measured pressure (psi)', 'decimal'),
                'cause' => self::field('Cause found', 'text'),
                'action_taken' => self::field('Action taken', 'textarea'),
                'resolved' => self::field('Resolved?', 'select', options: $yesNo),
                'follow_up' => self::field('Follow-up order to create', 'select', options: [
                    self::LeakRepair->value => self::LeakRepair->label(),
                    self::PipeRepair->value => self::PipeRepair->label(),
                ]) + ['when' => ['resolved' => 'no']],
            ],
            self::WaterQualityComplaint => [
                'complaint_type' => self::field('Complaint', 'select', options: [
                    'color' => 'Color',
                    'odor' => 'Odor',
                    'taste' => 'Taste',
                    'particles' => 'Particles',
                ]),
                'sample_taken' => self::field('Sample taken?', 'select', options: $yesNo),
                'sample_id' => self::field('Sample ID', 'text', max: 50) + ['when' => ['sample_taken' => 'yes']],
                'result' => self::field('Result', 'select', options: [
                    'pass' => 'Pass',
                    'fail' => 'Fail',
                    'pending_lab' => 'Pending lab result',
                ]),
                'action_taken' => self::field('Action taken', 'textarea', hint: 'e.g. Line flushing'),
            ],
            self::BillingComplaint => [
                'bill_period' => self::field('Bill period', 'month'),
                'issue' => self::field('Issue', 'select', options: [
                    'wrong_reading' => 'Wrong reading',
                    'wrong_rate' => 'Wrong rate',
                    'wrong_charges' => 'Wrong charges',
                    'payment_not_posted' => 'Payment not posted',
                    'other' => 'Other',
                ]),
                'findings' => self::field('Findings', 'textarea'),
                'outcome' => self::field('Outcome', 'select', options: [
                    'no_error' => 'No error found',
                    'adjustment_needed' => 'Adjustment needed',
                    'reread_needed' => 'Re-read needed (creates a Meter Re-reading)',
                ]),
                'adjustment_amount' => self::field('Adjustment amount', 'amount') + ['when' => ['outcome' => 'adjustment_needed']],
            ],
            self::ChangeOfOwnership => [
                'new_first_name' => self::field('New owner first name', 'text', max: 75),
                'new_last_name' => self::field('New owner last name', 'text', max: 75),
                'new_company_name' => self::field('New owner company', 'text', required: false, max: 150),
                'new_email' => self::field('New owner email', 'email', required: false, max: 150),
                'new_phone' => self::field('New owner phone', 'phone', required: false, max: 30),
                'effective_date' => self::field('Effective date', 'date'),
                'final_reading' => self::reading('Final reading for the previous owner', ReadingKind::Final, 'current') + ['ifMeter' => true],
            ],
        };
    }

    /**
     * Items that must all be ticked before the order can be completed. An item given as
     * [label, [field => value]] only applies when that answer matches.
     *
     * @return list<string|array{0: string, 1: array<string, string>}>
     */
    public function checklist(): array
    {
        return match ($this) {
            self::NewInstallation => ['Tapping / connection done', 'Meter sealed', 'No leaks at the connection', 'Customer informed'],
            self::Disconnection => ['Valve closed', 'Meter sealed / locked', 'Notice left with the customer'],
            self::Reconnection => ['Seal removed', 'Valve opened', 'Meter running', 'No leaks'],
            self::MeterReplacement => ['Old meter removed', 'New meter sealed', 'No leaks'],
            self::MeterRepair => [['Meter tested and running', ['outcome' => 'repaired']], ['Meter sealed', ['outcome' => 'repaired']]],
            self::MeterRelocation => ['Meter re-sealed', 'No leaks'],
            self::MeterReadingVerification => ['Meter checked for tampering'],
            self::LeakRepair => ['Leak stopped', 'Area restored'],
            self::PipeRepair => ['Water restored', 'Pressure tested'],
            self::LowWaterPressure => ['Pressure re-tested'],
            self::WaterQualityComplaint => ['Customer advised'],
            self::BillingComplaint => [],
            self::ChangeOfOwnership => ['Proof of ownership verified', 'Valid ID verified'],
        };
    }

    /**
     * @param  array<string, string>  $options
     * @return array{label: string, kind: string, required: bool, max?: int, options?: array<string, string>, hint?: string}
     */
    private static function field(string $label, string $kind, bool $required = true, array $options = [], ?string $hint = null, ?int $max = null): array
    {
        return array_filter(
            ['label' => $label, 'kind' => $kind, 'required' => $required, 'max' => $max, 'options' => $options, 'hint' => $hint],
            fn (mixed $value): bool => $value !== [] && $value !== null,
        );
    }

    /**
     * @return array{label: string, kind: string, required: bool, reading: ReadingKind, meter: string}
     */
    private static function reading(string $label, ReadingKind $kind, string $meter): array
    {
        return ['label' => $label, 'kind' => 'reading', 'required' => true, 'reading' => $kind, 'meter' => $meter];
    }
}
