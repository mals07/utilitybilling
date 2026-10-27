<?php

namespace App\Support\Reader;

use App\Enums\DiscrepancySeverity;
use App\Enums\InvoiceLineKind;
use App\Enums\NumberGapReason;
use App\Enums\ReadingCode;
use App\Enums\SyncCheck;
use App\Enums\SyncItemStatus;
use App\Enums\SyncItemType;
use App\Enums\SyncResolution;
use App\Models\Customer;
use App\Models\Device;
use App\Models\RoutePackage;
use App\Models\SyncBatch;
use App\Models\SyncItem;
use App\Models\User;
use App\Support\Billing\BillingException;
use App\Support\Billing\BillingRun;
use App\Support\Billing\MeterReadings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Uploads from meter reader devices: a batch is opened, its items arrive in chunks, and on commit
 * every item is checked (App\Support\Reader\SyncValidator) and applied to the live billing data or
 * held for a supervisor.
 *
 * A reading item's payload, as the device sends it:
 * - client_uuid, type ("reading"), customer_id, billing_period, meter_number
 * - reading_code, present_reading (null when estimated), previous_reading, flags (review reasons)
 * - confirmed_by_reader (re-entered and photographed at the meter), read_at (device time, ISO 8601)
 * - latitude, longitude, notes, customer_version, config_hash
 * - bill (null when nothing was printed): invoice_number, invoice_date, lines (kind, description,
 *   quantity, rate, cents, charge_element_id, work_order_id, source_invoice_id), tax (name, rate,
 *   inclusive, taxableCents, taxCents, exemptCents), total_cents, previous_balance_cents, printed_at,
 *   reprint_count (times printed again after the first, e.g. when the customer lost the bill)
 *
 * A skipped number item: client_uuid, type ("skipped_number"), invoice_number, note.
 */
final class SyncItems
{
    /**
     * Items accepted per upload request.
     */
    public const CHUNK_SIZE = 50;

    public static function open(Device $device, RoutePackage $package, int $itemCount, string $checksum, string $deviceTime): SyncBatch
    {
        return SyncBatch::query()->create([
            'company_id' => $device->company_id,
            'device_id' => $device->id,
            'route_package_id' => $package->id,
            'item_count' => $itemCount,
            'checksum' => $checksum,
            'received_uuids' => [],
            'device_time' => Carbon::parse($deviceTime),
        ]);
    }

    /**
     * Stores a chunk of items. Sending a chunk again is harmless: an item already uploaded is not
     * stored twice, and one delivered by an earlier batch keeps that batch's outcome.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws ReaderException When the batch was already committed.
     */
    public static function receive(SyncBatch $batch, array $items): int
    {
        return DB::transaction(function () use ($batch, $items): int {
            $batch = SyncBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->isCommitted()) {
                throw new ReaderException('This batch was already committed. Start a new one.', 409);
            }

            $uuids = $batch->received_uuids;
            foreach ($items as $payload) {
                $uuid = mb_strtolower($payload['client_uuid']);
                $uuids[] = $uuid;

                $existing = SyncItem::query()->where('client_uuid', $uuid)->first();
                if ($existing !== null) {
                    // Left over from a batch that was never committed: it moves to this one.
                    if ($existing->status === SyncItemStatus::Received) {
                        $existing->update(['sync_batch_id' => $batch->id, 'payload' => $payload]);
                    }

                    continue;
                }

                $type = SyncItemType::from($payload['type']);
                SyncItem::query()->create([
                    'company_id' => $batch->company_id,
                    'sync_batch_id' => $batch->id,
                    'device_id' => $batch->device_id,
                    'client_uuid' => $uuid,
                    'type' => $type,
                    'customer_id' => $type === SyncItemType::Reading ? $payload['customer_id'] : null,
                    'billing_period' => $type === SyncItemType::Reading ? $payload['billing_period'] : null,
                    'invoice_number' => $type === SyncItemType::Reading ? ($payload['bill']['invoice_number'] ?? null) : $payload['invoice_number'],
                    'payload' => $payload,
                    'status' => SyncItemStatus::Received,
                ]);
            }

            $batch->update(['received_uuids' => array_values(array_unique($uuids))]);

            return count($batch->received_uuids);
        });
    }

    /**
     * Once every item is in, checks and applies them, in the order they were printed. Committing
     * a batch again answers with the same outcome.
     *
     * @return list<array<string, mixed>> The outcome of each item, for the device.
     *
     * @throws ReaderException When items are missing or the checksum doesn't match.
     */
    public static function commit(SyncBatch $batch): array
    {
        $batch = DB::transaction(function () use ($batch): SyncBatch {
            $batch = SyncBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->isCommitted()) {
                return $batch;
            }

            $uuids = $batch->received_uuids;
            sort($uuids);
            if (count($uuids) !== $batch->item_count) {
                throw new ReaderException('Received '.count($uuids)." of {$batch->item_count} items. Upload the rest, then commit again.");
            }
            if (! hash_equals($batch->checksum, hash('sha256', implode("\n", $uuids)))) {
                throw new ReaderException('The batch checksum doesn\'t match the items received. Upload the batch again.');
            }

            $items = SyncItem::query()
                ->where('sync_batch_id', $batch->id)
                ->where('status', SyncItemStatus::Received)
                ->orderByRaw('invoice_number is null')
                ->orderBy('invoice_number')
                ->orderBy('id')
                ->get();

            foreach ($items as $item) {
                self::process($item, $batch);
            }

            $batch->update(['committed_at' => now()]);
            $batch->device->forceFill(['last_synced_at' => now()])->saveQuietly();

            return $batch;
        });

        return SyncItem::query()
            ->whereIn('client_uuid', $batch->received_uuids)
            ->with(['invoice', 'discrepancies'])
            ->orderBy('id')
            ->get()
            ->map(self::outcome(...))
            ->all();
    }

    /**
     * What the device is told about an item.
     *
     * @return array<string, mixed>
     */
    public static function outcome(SyncItem $item): array
    {
        return [
            'client_uuid' => $item->client_uuid,
            'status' => $item->status->value,
            'resolution' => $item->resolution?->value,
            'invoice_number' => $item->invoice?->invoice_number,
            'discrepancies' => $item->discrepancies->map(fn ($discrepancy): array => [
                'check' => $discrepancy->check->value,
                'severity' => $discrepancy->severity->value,
                'message' => $discrepancy->message,
            ])->all(),
        ];
    }

    /**
     * Resolves an item held for review.
     *
     * @throws BillingException When the item can't be applied as asked, e.g. the customer was billed meanwhile.
     */
    public static function resolve(SyncItem $item, SyncResolution $resolution, string $note, User $supervisor): void
    {
        DB::transaction(function () use ($item, $resolution, $note, $supervisor): void {
            $item = SyncItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! $item->isHeld()) {
                throw new BillingException('This item was already resolved.');
            }

            $resolved = ['resolution' => $resolution, 'resolution_note' => mb_substr($note, 0, 500), 'resolved_by_id' => $supervisor->id, 'resolved_at' => now()];

            if ($resolution === SyncResolution::Rejected) {
                $block = $item->invoice_number === null ? null : NumberBlocks::freeBlockFor($item->device, $item->invoice_number);
                if ($block !== null) {
                    NumberBlocks::recordGap($block, (int) $item->invoice_number, NumberGapReason::Rejected, $note);
                }
                $item->update([...$resolved, 'status' => SyncItemStatus::Rejected]);

                return;
            }

            if ($item->invoice_number !== null && NumberBlocks::freeBlockFor($item->device, $item->invoice_number) === null) {
                throw new BillingException("Invoice number {$item->invoice_number} isn't free to use. Reject the reading instead.");
            }

            self::apply($item, $resolution);
            $item->update($resolved);
        });
    }

    /**
     * Checks one item and applies it, or holds it with what was found.
     */
    private static function process(SyncItem $item, SyncBatch $batch): void
    {
        if ($item->type === SyncItemType::SkippedNumber) {
            $block = NumberBlocks::freeBlockFor($batch->device, (string) $item->invoice_number);
            if ($block === null) {
                self::hold($item, [['check' => SyncCheck::InvoiceNumber, 'severity' => DiscrepancySeverity::Blocking, 'message' => 'The skipped invoice number isn\'t one reserved for this device, or it is already used.', 'device_value' => $item->invoice_number, 'server_value' => null]]);

                return;
            }

            NumberBlocks::recordGap($block, (int) $item->invoice_number, NumberGapReason::Skipped, $item->payload['note'] ?? null);
            $item->update(['status' => SyncItemStatus::Applied]);

            return;
        }

        $findings = SyncValidator::check($item, $batch);
        $blocking = array_filter($findings, fn (array $finding): bool => $finding['severity'] === DiscrepancySeverity::Blocking);

        if ($blocking !== []) {
            self::hold($item, $findings);

            return;
        }

        try {
            DB::transaction(fn () => self::apply($item, null));
        } catch (BillingException $exception) {
            self::hold($item, [...$findings, ['check' => SyncCheck::Billing, 'severity' => DiscrepancySeverity::Blocking, 'message' => $exception->getMessage(), 'device_value' => null, 'server_value' => null]]);

            return;
        }

        self::record($item, $findings);
    }

    /**
     * Saves the reading and issues the bill printed for it, with the invoice number it was printed with.
     * Without a resolution the server's figures are used (they matched the printed bill); a supervisor
     * may accept the printed figures instead, or re-bill with the server's.
     *
     * @throws BillingException
     */
    private static function apply(SyncItem $item, ?SyncResolution $resolution): void
    {
        $payload = $item->payload;
        $device = $item->device;
        $reader = $device->user;
        $company = $device->company;
        $customer = Customer::query()->findOrFail($item->customer_id);
        $readAt = Carbon::parse($payload['read_at']);
        $bill = $payload['bill'] ?? null;

        $reading = MeterReadings::save(
            $customer,
            (string) $item->billing_period,
            (string) ($payload['present_reading'] ?? ''),
            ReadingCode::from($payload['reading_code']),
            $readAt->copy()->setTimezone($company->timezone ?: config('app.timezone'))->toDateString(),
            $reader,
            confirmed: $resolution !== null || (($payload['confirmed_by_reader'] ?? false) && $company->readers_confirm_flagged),
        );
        $reading->update([
            'device_id' => $device->id,
            'read_at' => $readAt,
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'photo_path' => $payload['photo_path'] ?? null,
            'notes' => isset($payload['notes']) ? mb_substr((string) $payload['notes'], 0, 500) : $reading->notes,
            'confirmed_by_reader' => (bool) ($payload['confirmed_by_reader'] ?? false),
        ]);

        $invoice = null;
        if ($bill !== null) {
            $printed = $resolution === SyncResolution::AcceptedAsPrinted ? self::printedBill($bill) : null;
            $invoice = BillingRun::generateFor($reading, (string) $bill['invoice_date'], $reader, (string) $bill['invoice_number'], $printed);
            $invoice->update([
                'device_id' => $device->id,
                'printed_at' => isset($bill['printed_at']) ? Carbon::parse($bill['printed_at']) : null,
                'reprint_count' => (int) ($bill['reprint_count'] ?? 0),
                'is_corrected' => $resolution === SyncResolution::Rebilled,
            ]);
        }

        $item->update(['status' => SyncItemStatus::Applied, 'meter_reading_id' => $reading->id, 'invoice_id' => $invoice?->id]);
    }

    /**
     * The printed bill in the shape App\Support\Billing\BillingRun issues invoices from.
     *
     * @param  array<string, mixed>  $bill
     * @return array{lines: list<array<string, mixed>>, tax: array<string, mixed>}
     */
    private static function printedBill(array $bill): array
    {
        return [
            'lines' => array_map(fn (array $line): array => [
                'kind' => InvoiceLineKind::from($line['kind']),
                'description' => (string) $line['description'],
                'quantity' => $line['quantity'] ?? null,
                'rate' => $line['rate'] ?? null,
                'cents' => (int) $line['cents'],
                'charge_element_id' => $line['charge_element_id'] ?? null,
                'work_order_id' => $line['work_order_id'] ?? null,
                'source_invoice_id' => $line['source_invoice_id'] ?? null,
            ], array_values($bill['lines'])),
            'tax' => [
                'name' => $bill['tax']['name'] ?? null,
                'rate' => $bill['tax']['rate'] ?? null,
                'inclusive' => (bool) ($bill['tax']['inclusive'] ?? false),
                'taxableCents' => (int) ($bill['tax']['taxableCents'] ?? 0),
                'taxCents' => (int) ($bill['tax']['taxCents'] ?? 0),
                'exemptCents' => (int) ($bill['tax']['exemptCents'] ?? 0),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private static function hold(SyncItem $item, array $findings): void
    {
        $item->update(['status' => SyncItemStatus::Held]);
        self::record($item, $findings);
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     */
    private static function record(SyncItem $item, array $findings): void
    {
        foreach ($findings as $finding) {
            $item->discrepancies()->create([
                'company_id' => $item->company_id,
                'check' => $finding['check'],
                'severity' => $finding['severity'],
                'message' => mb_substr($finding['message'], 0, 500),
                'device_value' => $finding['device_value'] === null ? null : mb_substr($finding['device_value'], 0, 500),
                'server_value' => $finding['server_value'] === null ? null : mb_substr($finding['server_value'], 0, 500),
            ]);
        }
    }
}
