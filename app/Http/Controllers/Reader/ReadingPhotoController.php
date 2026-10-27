<?php

namespace App\Http\Controllers\Reader;

use App\Http\Controllers\Controller;
use App\Models\MeterReading;
use App\Models\SyncItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The photo of a meter taken with a reading, uploaded after the reading's batch. Stored privately;
 * the Discrepancies and Meter Readings pages show it.
 */
class ReadingPhotoController extends Controller
{
    public function __invoke(Request $request, string $clientUuid): JsonResponse
    {
        $device = $request->attributes->get('device');
        $item = SyncItem::query()->where('device_id', $device->id)->where('client_uuid', mb_strtolower($clientUuid))->firstOrFail();

        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']]);

        $path = $request->file('photo')->storeAs("reader-photos/{$item->company_id}", "{$item->client_uuid}.{$request->file('photo')->extension()}");

        $item->update(['payload' => [...$item->payload, 'photo_path' => $path]]);
        if ($item->meter_reading_id !== null) {
            MeterReading::query()->whereKey($item->meter_reading_id)->update(['photo_path' => $path]);
        }

        return response()->json(['stored' => true]);
    }
}
