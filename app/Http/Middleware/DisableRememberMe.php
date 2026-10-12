<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Remember me" would let a long-lived cookie silently re-create sessions and bypass
 * the 30-minute idle / 8-hour absolute limits (Spec 3.3), so the flag is ignored.
 */
class DisableRememberMe
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->request->remove('remember');
        $request->query->remove('remember');

        if ($request->isJson()) {
            $request->json()->remove('remember');
        }

        return $next($request);
    }
}
