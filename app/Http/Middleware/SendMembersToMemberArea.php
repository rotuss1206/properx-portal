<?php

namespace App\Http\Middleware;

use App\Member\MemberMenu;
use App\Nova\Menu\SidebarMenu;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * With the member area on (MEMBER_AREA_ENABLED), members opening a Nova page land on
 * /member instead, including right after sign-in (Nova redirects to its initial path).
 * Two-factor setup and the password stay on Nova's User Security page, and signing out is
 * Nova's. Nova's profile pages lead to /member/profile, their certificate block to
 * /member/certificate, its license documents to /member/license-documents, its library to
 * /member/library (the last two for those who may see them). Staff and Nova's API requests are
 * not touched. Part of nova.api_middleware, after RequireTwoFactor.
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

        $target = $this->target($request);

        // An Inertia visit inside Nova must leave the Nova app for a full page load.
        return $request->header('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    /**
     * Pages with a place in the member area lead there, also from old links; the rest to the dashboard.
     */
    private function target(Request $request): string
    {
        $novaPath = trim(Nova::path(), '/');

        return match (true) {
            $request->is("{$novaPath}/resources/tax-documents", "{$novaPath}/resources/tax-documents/*") => MemberMenu::movedUrl($request->user(), 'tax-documents'),
            $request->query(SidebarMenu::SECTION) === 'notifications' && $this->isProfilePage($request, $novaPath) => MemberMenu::movedUrl($request->user(), 'notifications'),
            $request->query(SidebarMenu::SECTION) === 'certificate' && $this->isProfilePage($request, $novaPath) => route('member.certificate'),
            $this->isProfilePage($request, $novaPath) => route('member.profile'),
            $request->is("{$novaPath}/resources/license-documents", "{$novaPath}/resources/license-documents/*") && MemberMenu::visible($request->user(), 'license-documents') => route('member.license-documents'),
            $request->is("{$novaPath}/resources/library-documents", "{$novaPath}/resources/library-documents/*") && MemberMenu::visible($request->user(), 'library') => route('member.library'),
            default => route('member.dashboard'),
        };
    }

    private function isProfilePage(Request $request, string $novaPath): bool
    {
        return $request->is("{$novaPath}/resources/member-profiles", "{$novaPath}/resources/member-profiles/*");
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
