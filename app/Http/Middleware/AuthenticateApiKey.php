<?php

namespace App\Http\Middleware;

use App\Services\ApiKeys\ApiKeyManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine-to-machine calls authenticated with the API Service key (Spec 3.7, 8).
 * Accepts "Authorization: Bearer <key>" or "X-Api-Key: <key>".
 * Usage: ->middleware('auth.api-key') or ->middleware('auth.api-key:some.permission').
 */
class AuthenticateApiKey
{
    public function __construct(private ApiKeyManager $keys) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $account = $this->keys->authenticate($request->bearerToken() ?? $request->header('X-Api-Key'));

        if ($account === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Bearer',
            ]);
        }

        if ($permission !== null && ! $account->checkPermissionTo($permission)) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        Auth::setUser($account);

        return $next($request);
    }
}
