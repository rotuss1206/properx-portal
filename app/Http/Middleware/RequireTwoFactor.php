<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Security\TwoFactorSetupController;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every Nova user (staff and members) must enrol in TOTP two-factor authentication
 * before using the portal (KEENEY platform rule, Spec 8). Until then, and until they have
 * acknowledged their recovery codes, people are sent to the setup pages
 * (App\Http\Controllers\Security\TwoFactorSetupController); only signing out stays open.
 */
class RequireTwoFactor
{
    /**
     * Paths (relative to the Nova path) that stay reachable during enrolment.
     */
    private const ALLOWED_NOVA_PATHS = [
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

        if ($user === null || ! $this->mustFinishSetup($request, $user) || $this->isAllowed($request)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'Two-factor authentication must be enabled before accessing this resource.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Inertia visits leave the Nova app for the setup pages with a full page load.
        return $request->header('X-Inertia')
            ? Inertia::location(route('security.two-factor'))
            : redirect()->route('security.two-factor');
    }

    private function mustFinishSetup(Request $request, $user): bool
    {
        return ($request->hasSession() && $request->session()->get(TwoFactorSetupController::PENDING_CODES_KEY))
            || ($user->requiresTwoFactor() && ! $user->hasConfirmedTwoFactor());
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
