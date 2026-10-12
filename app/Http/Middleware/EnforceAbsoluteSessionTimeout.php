<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends an authenticated session 8 hours after login, however active it is (Spec 3.3).
 * The 30-minute idle timeout is handled by SESSION_LIFETIME.
 */
class EnforceAbsoluteSessionTimeout
{
    public const SESSION_KEY = 'auth.started_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $startedAt = $session->get(self::SESSION_KEY);

        if ($startedAt === null) {
            $session->put(self::SESSION_KEY, now()->getTimestamp());

            return $next($request);
        }

        if (now()->getTimestamp() - (int) $startedAt < config('portal.security.session_absolute_minutes') * 60) {
            return $next($request);
        }

        Auth::guard()->logout();
        $session->invalidate();
        $session->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['message' => 'Your session has expired. Please sign in again.'], Response::HTTP_UNAUTHORIZED)
            : redirect()->guest(url(Nova::path().'/login'));
    }
}
