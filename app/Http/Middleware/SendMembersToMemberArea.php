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
 * With the member area on (MEMBER_AREA_ENABLED), Nova is closed to members (individual members,
 * principals and agency people): a Nova page leads to its place in /member (right after sign-in
 * App\Auth\SignInResponse sends them there directly), and anything else, Nova's API included, is
 * a 403. Only signing out stays Nova's (the member area's header posts to nova.logout). Nova's profile
 * pages lead to /member/profile, User Security to its security section, the certificate block to
 * /member/certificate, license documents to /member/license-documents, the library to
 * /member/library (the last two for those who may see them). Staff are not touched. Part of
 * nova.api_middleware (Nova's pages and API), after RequireTwoFactor.
 */
class SendMembersToMemberArea
{
    /**
     * What members may still reach (relative to the Nova path): signing out.
     */
    private const KEPT_NOVA_PATHS = [
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->applies($request)) {
            return $next($request);
        }

        $novaPath = trim(Nova::path(), '/');

        // Nova's API, its tools' routes and any form post: closed.
        if (! $request->isMethod('GET') || ! $request->is($novaPath, "{$novaPath}/*")) {
            abort(Response::HTTP_FORBIDDEN, 'Members use the member area.');
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
            $request->is("{$novaPath}/user-security", "{$novaPath}/user-security/*") => route('member.profile', ['edit' => 'security']),
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
            && $user !== null
            && $user->isMember()
            && ! $user->isStaff()
            && ! $request->is(...array_map(fn (string $path) => "{$novaPath}/{$path}", self::KEPT_NOVA_PATHS));
    }
}
