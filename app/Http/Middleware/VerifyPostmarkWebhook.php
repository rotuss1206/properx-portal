<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Postmark webhooks carry no signature; they are protected with HTTP basic auth
 * credentials embedded in the webhook URL configured in Postmark.
 */
class VerifyPostmarkWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = (string) config('services.postmark.webhook.username');
        $password = (string) config('services.postmark.webhook.password');

        // Refuse everything until credentials are configured.
        $authorised = $username !== '' && $password !== ''
            && hash_equals($username, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword());

        if (! $authorised) {
            return response('Unauthorized', Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Basic realm="postmark"']);
        }

        return $next($request);
    }
}
