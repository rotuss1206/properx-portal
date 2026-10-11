<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff must enrol in TOTP two-factor authentication before using Nova (ТЗ, розділ 8).
 * Until then only the User Security page (and what it needs) is reachable.
 */
class RequireTwoFactorForStaff
{
    /**
     * Paths (relative to the Nova path) that stay reachable during enrolment.
     */
    private const ALLOWED_NOVA_PATHS = [
        'user-security',
        'user-security/*',
        'logout',
    ];

    private const ALLOWED_API_PATHS = [
        'nova-api/nova-notifications',
        'nova-api/nova-notifications/*',
        'nova-api/scripts/*',
        'nova-api/styles/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->requiresTwoFactor() || $user->hasConfirmedTwoFactor() || $this->isAllowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Two-factor authentication must be enabled before accessing this resource.',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('nova.pages.user-security');
    }

    private function isAllowed(Request $request): bool
    {
        $novaPath = trim(Nova::path(), '/');

        $patterns = array_merge(
            array_map(fn (string $path) => "{$novaPath}/{$path}", self::ALLOWED_NOVA_PATHS),
            self::ALLOWED_API_PATHS,
        );

        return $request->is(...$patterns);
    }
}
