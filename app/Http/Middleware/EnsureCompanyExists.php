<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends users who haven't set up their company profile to Company Setup.
 */
class EnsureCompanyExists
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasCompany()) {
            return redirect()->route('company');
        }

        return $next($request);
    }
}
