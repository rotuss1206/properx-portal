<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Nova dashboard page this person may not see (a member on the staff dashboard, staff on the
 * member's) would be a 404: an old link, or the address they opened before signing in. They get
 * their own home page instead. Part of nova.api_middleware, after SendMembersToMemberArea.
 */
class RedirectUnavailableNovaDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('nova.pages.dashboard.custom')
            || Nova::dashboardForKey((string) $request->route('name'), NovaRequest::createFrom($request)) !== null) {
            return $next($request);
        }

        $home = Nova::initialPathUrl($request);

        return $request->header('X-Inertia') ? Inertia::location($home) : redirect($home);
    }
}
