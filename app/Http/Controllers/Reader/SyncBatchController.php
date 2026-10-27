<?php

namespace App\Http\Controllers\Reader;

use App\Enums\ReadingCode;
use App\Enums\SyncItemType;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RoutePackage;
use App\Models\SyncBatch;
use App\Models\SyncItem;
use App\Support\Reader\SyncItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Uploads from a device: open a batch, send its items in chunks, commit it. App\Support\Reader\SyncItems
 * describes the items.
 */
class SyncBatchController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $validated = $request->validate([
            'package_id' => ['required', 'integer'],
            'item_count' => ['required', 'integer', 'min:1', 'max:5000'],
            'checksum' => ['required', 'string', 'size:64'],
            'device_time' => ['required', 'date'],
        ]);

        $package = RoutePackage::query()->where('device_id', $device->id)->findOrFail($validated['package_id']);
        $batch = SyncItems::open($device, $package, $validated['item_count'], mb_strtolower($validated['checksum']), $validated['device_time']);

        return response()->json(['batch_id' => $batch->id], 201);
    }

    public function items(Request $request, SyncBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.SyncItems::CHUNK_SIZE],
            'items.*.client_uuid' => ['required', 'uuid'],
            'items.*.type' => ['required', Rule::enum(SyncItemType::class)],
            'items.*.invoice_number' => ['required_if:items.*.type,skipped_number', 'nullable', 'regex:/^\d{1,18}$/'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
            'items.*.customer_id' => ['required_if:items.*.type,reading', 'nullable', 'integer'],
            'items.*.billing_period' => ['required_if:items.*.type,reading', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'items.*.meter_number' => ['nullable', 'string', 'max:50'],
            'items.*.reading_code' => ['required_if:items.*.type,reading', 'nullable', Rule::enum(ReadingCode::class)],
            'items.*.present_reading' => ['nullable', 'string', 'max:20'],
            'items.*.previous_reading' => ['required_if:items.*.type,reading', 'nullable', 'numeric'],
            'items.*.flags' => ['nullable', 'array'],
            'items.*.flags.*' => ['string', 'max:500'],
            'items.*.confirmed_by_reader' => ['nullable', 'boolean'],
            'items.*.read_at' => ['required_if:items.*.type,reading', 'nullable', 'date'],
            'items.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'items.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.customer_version' => ['nullable', 'string', 'max:64'],
            'items.*.config_hash' => ['nullable', 'string', 'max:64'],
            'items.*.bill' => ['nullable', 'array'],
            'items.*.bill.invoice_number' => ['required_with:items.*.bill', 'regex:/^\d{1,18}$/'],
            'items.*.bill.invoice_date' => ['required_with:items.*.bill', 'date_format:Y-m-d'],
            'items.*.bill.lines' => ['required_with:items.*.bill', 'array'],
            'items.*.bill.lines.*.kind' => ['required', 'string', 'max:20'],
            'items.*.bill.lines.*.description' => ['required', 'string', 'max:255'],
            'items.*.bill.lines.*.cents' => ['required', 'integer'],
            'items.*.bill.lines.*.quantity' => ['nullable', 'numeric'],
            'items.*.bill.lines.*.rate' => ['nullable', 'numeric'],
            'items.*.bill.lines.*.charge_element_id' => ['nullable', 'integer'],
            'items.*.bill.lines.*.work_order_id' => ['nullable', 'integer'],
            'items.*.bill.lines.*.source_invoice_id' => ['nullable', 'integer'],
            'items.*.bill.tax' => ['nullable', 'array'],
            'items.*.bill.tax.name' => ['nullable', 'string', 'max:30'],
            'items.*.bill.tax.rate' => ['nullable', 'numeric'],
            'items.*.bill.tax.inclusive' => ['nullable', 'boolean'],
            'items.*.bill.tax.taxableCents' => ['nullable', 'integer'],
            'items.*.bill.tax.taxCents' => ['nullable', 'integer'],
            'items.*.bill.tax.exemptCents' => ['nullable', 'integer'],
            'items.*.bill.total_cents' => ['required_with:items.*.bill', 'integer'],
            'items.*.bill.previous_balance_cents' => ['nullable', 'integer'],
            'items.*.bill.printed_at' => ['nullable', 'date'],
            'items.*.bill.reprint_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        // Only the validated fields are kept, so a device can't set anything the server sets itself.
        return response()->json(['received' => SyncItems::receive($batch, $validated['items']), 'expected' => $batch->item_count]);
    }

    public function commit(Request $request, SyncBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        return response()->json(['items' => SyncItems::commit($batch)]);
    }

    /**
     * Outcomes of the device's held items that a supervisor resolved since the given time.
     */
    public function results(Request $request): JsonResponse
    {
        $validated = $request->validate(['since' => ['required', 'date']]);

        $items = SyncItem::query()
            ->where('device_id', $this->device($request)->id)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>', $validated['since'])
            ->with(['invoice', 'discrepancies'])
            ->orderBy('resolved_at')
            ->get();

        return response()->json([
            'as_of' => now()->toIso8601String(),
            'items' => $items->map(SyncItems::outcome(...))->all(),
        ]);
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private function authorizeBatch(Request $request, SyncBatch $batch): void
    {
        abort_unless($batch->device_id === $this->device($request)->id, 404);
    }
}
