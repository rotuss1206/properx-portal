<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Nova\Nova;
use Symfony\Component\HttpFoundation\Response;

/**
 * An agency person who set their password through an invitation finishes it (profile check and
 * consents, Join\InvitationController) before using the portal. Runs after RequireTwoFactor, so
 * the two-factor setup comes first; signing out stays open.
 */
class RequireAcceptedInvitation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isMember() || $user->isStaff() || $request->is(trim(Nova::path(), '/').'/logout')
            || ! $user->memberProfile?->mustFinishInvitation()) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Finish setting up your account first.'], Response::HTTP_FORBIDDEN);
        }

        return $request->header('X-Inertia')
            ? Inertia::location(route('join.invitation.complete'))
            : redirect()->route('join.invitation.complete');
    }
}
