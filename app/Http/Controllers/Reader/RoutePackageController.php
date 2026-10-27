<?php

namespace App\Http\Controllers\Reader;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RoutePackage;
use App\Support\Billing\BillingRun;
use App\Support\Reader\ReaderException;
use App\Support\Reader\RoutePackages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The route package a device downloads to read offline, and what changed since it.
 */
class RoutePackageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $today = BillingRun::today($device->company);

        $validated = $request->validate([
            'billing_period' => ['nullable', 'string'],
            'release_from' => ['nullable', 'regex:/^\d{1,18}$/'],
        ]);

        $period = $validated['billing_period'] ?? substr($today, 0, 7);
        if ($error = BillingRun::periodError($period, $today)) {
            throw new ReaderException($error);
        }

        return response()->json(RoutePackages::create(
            $device,
            $period,
            isset($validated['release_from']) ? (int) $validated['release_from'] : null,
        ), 201);
    }

    public function changes(Request $request, RoutePackage $package): JsonResponse
    {
        abort_unless($package->device_id === $request->attributes->get('device')->id, 404);

        return response()->json(RoutePackages::changesSince($package));
    }
}
