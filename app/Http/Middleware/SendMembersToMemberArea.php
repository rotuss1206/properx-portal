<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * With the member area on (MEMBER_AREA_ENABLED), members opening a Nova page land on
 * /member instead, including right after sign-in (Nova redirects to its initial path).
 * Two-factor setup and the password stay on Nova's User Security page, and signing out is
 * Nova's. Staff and Nova's API requests are not touched. Part of nova.api_middleware,
 * after RequireTwoFactor.
 */
class SendMembersToMemberArea
{
    /**
     * Nova pages members keep using (relative to the Nova path).
     */
    private const KEPT_NOVA_PATHS = [
        'user-security',
        'user-security/*',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->applies($request)) {
            return $next($request);
        }

        $target = route('member.dashboard');

        // An Inertia visit inside Nova must leave the Nova app for a full page load.
        return $request->header('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    private function applies(Request $request): bool
    {
        $user = $request->user();
        $novaPath = trim(Nova::path(), '/');

        return config('portal.member_area.enabled')
            && $request->isMethod('GET')
            && $user !== null
            && $user->isMember()
            && ! $user->isStaff()
            && $request->is($novaPath, "{$novaPath}/*")
            && ! $request->is(...array_map(fn (string $path) => "{$novaPath}/{$path}", self::KEPT_NOVA_PATHS));
    }
}
