<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers required by Spec 8: HSTS, X-Content-Type-Options,
 * X-Frame-Options and a Content-Security-Policy that Nova works with.
 *
 * Nova renders its inline <script> tags with the per-request nonce (see AppServiceProvider),
 * so scripts need no 'unsafe-inline'. Styles keep 'unsafe-inline' because Nova injects
 * brand colours and component styles at runtime.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure() || app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age='.config('portal.security.hsts_max_age').'; includeSubDomains');
        }

        if (config('portal.security.csp_enabled')) {
            $headers->set(
                config('portal.security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy',
                $this->contentSecurityPolicy(Vite::cspNonce()),
            );
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
