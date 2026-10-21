<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users whose company hasn't finished the setup wizard (or doesn't exist yet) to the wizard.
 */
class EnsureCompanyExists
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->hasCompany() || ! $user->company->isSetUp()) {
            return redirect()->route('setup');
        }

        return $next($request);
    }
}
