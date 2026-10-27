<?php

namespace App\Http\Controllers\Reader;

use App\Http\Controllers\Controller;
use App\Support\Reader\Devices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The meter reader app's first, online sign-in: registers the phone and hands it its token.
 */
class DeviceRegistrationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:50'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        ['device' => $device, 'token' => $token] = Devices::register(
            $validated['login'],
            $validated['password'],
            $validated['device_name'],
            $validated['app_version'] ?? null,
        );

        $device->load('user');

        return response()->json([
            'token' => $token,
            'device' => ['id' => $device->id, 'name' => $device->name],
            'reader' => ['id' => $device->user->id, 'name' => $device->user->name],
            'company' => ['id' => $device->company_id, 'name' => $device->company->company_name],
        ], 201);
    }
}
