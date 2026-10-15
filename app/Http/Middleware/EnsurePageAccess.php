<?php

namespace App\Http\Middleware;

use App\Enums\Page;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows "Access denied" in place of a page the user's role has no View access to.
 */
class EnsurePageAccess
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        $page = Page::from($page);
        $user = $request->user();

        // Users without a company may always open Company Setup, since that is where they create it.
        $onboarding = $page === Page::CompanySetup && ! $user->hasCompany();

        if (! $onboarding && ! $user->canAccessPage($page)) {
            return response()->view('access-denied', ['title' => $page->label()], 403);
        }

        return $next($request);
    }
}
