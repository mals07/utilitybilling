<?php

namespace App\Http\Controllers\Reader;

use App\Enums\SyncItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\Reader\RoutePackages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The meter reader app's check before it syncs: the server's clock, whether the app is new enough to
 * bill, and whether the device was signed out.
 */
class StatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $validated = $request->validate(['app_version' => ['nullable', 'string', 'max:20']]);
        $appVersion = $validated['app_version'] ?? null;
        if ($appVersion !== null && $appVersion !== $device->app_version) {
            $device->forceFill(['app_version' => $appVersion])->saveQuietly();
        }

        $minimum = config('reader.minimum_app_version');

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'calc_version' => RoutePackages::CALC_VERSION,
            'minimum_app_version' => $minimum,
            'update_required' => $minimum !== null && $appVersion !== null && version_compare($appVersion, $minimum, '<'),
            'signed_out' => $device->isRevoked(),
            'token_expires_at' => $device->token_expires_at->toIso8601String(),
            'clock_drift_minutes' => (int) $device->company->reader_clock_drift_minutes,
            'held_count' => $device->syncItems()->where('status', SyncItemStatus::Held)->count(),
            'device' => ['id' => $device->id, 'name' => $device->name],
            'reader' => ['id' => $device->user->id, 'name' => $device->user->name],
        ]);
    }
}
