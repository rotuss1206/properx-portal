<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate of the member area (/member): a 404 while MEMBER_AREA_ENABLED is off, staff are sent
 * to Nova, and only the Member role gets in. Runs before "auth", so the area does not even
 * reveal a sign-in redirect while it is off; signed-in checks wait for a user.
 */
class EnsureMemberArea
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('portal.member_area.enabled'), Response::HTTP_NOT_FOUND);

        $user = $request->user();

        if ($user?->isStaff()) {
            return redirect(Nova::url('/'));
        }

        abort_if($user !== null && ! $user->isMember(), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
