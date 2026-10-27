<?php

namespace App\Http\Controllers\Reader;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\Reader\NumberBlocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hands back the invoice numbers a device didn't use, at the end of its route.
 */
class NumberBlockReleaseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $validated = $request->validate(['release_from' => ['required', 'regex:/^\d{1,18}$/']]);

        return response()->json(NumberBlocks::release($device, (int) $validated['release_from']));
    }
}
