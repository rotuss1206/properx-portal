<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nova throws a 500 when a metric of a dashboard hidden from the user is requested
 * (it assumes the dashboard exists). Answer 404 instead.
 */
class EnsureNovaDashboardIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $dashboard = $request->route('dashboard');

        if (is_string($dashboard) && Nova::dashboardForKey($dashboard, NovaRequest::createFrom($request)) === null) {
            abort(404);
        }

        return $next($request);
    }
}
