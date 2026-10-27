<?php

namespace App\Http\Middleware;

use App\Support\Reader\Devices;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs in a meter reader app request by its device token ("Authorization: Bearer <token>"). The
 * reader becomes the request's user, so queries see only their company's records; the device is
 * the request's "device" attribute.
 *
 * With the "uploads" parameter, a signed-out (revoked) device is let through too, for the routes that
 * upload what it already recorded.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $token = $request->bearerToken();
        $device = $token === null ? null : Devices::authenticate($token, allowRevoked: $mode === 'uploads');

        if ($device === null) {
            return response()->json(['message' => 'This device is signed out. Sign in again.'], 401);
        }

        Auth::setUser($device->user);
        $request->attributes->set('device', $device);

        return $next($request);
    }
}
