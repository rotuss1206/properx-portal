<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Security requirements from Spec 3.3 and 8.
 */
class SecurityTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    private function attemptLogin(string $email, string $password, string $ip = '203.0.113.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/nova/login', ['email' => $email, 'password' => $password]);
    }

    public function test_five_failed_logins_per_ip_lock_out_for_15_minutes(): void
    {
        $member = $this->member();

        foreach (range(1, 5) as $attempt) {
            $this->attemptLogin($member->email, 'wrong')->assertUnprocessable();
        }

        // Locked, even with the right password and another account.
        $this->attemptLogin($member->email, 'password')
            ->assertStatus(429);
        $this->attemptLogin($this->member()->email, 'password')->assertStatus(429);

        // Other IPs are unaffected.
        $this->attemptLogin($member->email, 'password', ip: '198.51.100.7')->assertOk();
        $this->post('/nova/logout');

        $this->travel(16)->minutes();
        $this->attemptLogin($member->email, 'password')->assertOk();
    }

    public function test_successful_logins_do_not_count_towards_the_limit(): void
    {
        $member = $this->member();

        foreach (range(1, 6) as $attempt) {
            $this->attemptLogin($member->email, 'password')->assertOk();
            $this->post('/nova/logout');
        }
    }

    public function test_two_factor_challenge_is_rate_limited(): void
    {
        $this->assertSame('two-factor', config('fortify.limiters.two-factor'));

        $route = collect(app('router')->getRoutes())->first(fn ($route) => $route->uri() === 'nova/user-security/two-factor-challenge'
            && in_array('POST', $route->methods(), true));

        $this->assertContains('throttle:two-factor', $route->gatherMiddleware());
    }

    public function test_session_settings_are_left_to_the_platform(): void
    {
        // The KEENEY platform sets SESSION_* (15 minutes, database, encrypted, secure);
        // config/session.php must keep Laravel's stock env-driven defaults.
        $config = file_get_contents(config_path('session.php'));

        $this->assertStringContainsString("'lifetime' => (int) env('SESSION_LIFETIME', 120)", $config);
        $this->assertStringContainsString("'secure' => env('SESSION_SECURE_COOKIE')", $config);
        $this->assertStringContainsString("'same_site' => env('SESSION_SAME_SITE', 'lax')", $config);
        $this->assertStringContainsString("Str::slug((string) env('APP_NAME', 'laravel')).'-session'", $config);
    }

    public function test_remember_me_is_ignored(): void
    {
        $member = $this->member(withTwoFactor: false);
        $tokenBefore = $member->remember_token;

        $response = $this->postJson('/nova/login', ['email' => $member->email, 'password' => 'password', 'remember' => true])
            ->assertOk();

        $this->assertAuthenticatedAs($member);
        $this->assertSame($tokenBefore, $member->fresh()->remember_token);
        $this->assertEmpty(array_filter(
            $response->headers->getCookies(),
            fn ($cookie) => str_starts_with($cookie->getName(), 'remember_'),
        ));
    }

    public function test_security_headers_and_csp_nonce_on_nova_pages(): void
    {
        $response = $this->get('https://localhost/nova/login')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $csp);

        preg_match("/'nonce-([^']+)'/", $csp, $nonce);
        $html = $response->getContent();

        // Every executable inline script must carry the nonce, or the browser blocks Nova.
        preg_match_all('/<script(?![^>]*type="application\/json")([^>]*)>/', $html, $scripts);
        $this->assertNotEmpty($scripts[1]);
        foreach ($scripts[1] as $attributes) {
            $this->assertStringContainsString('nonce="'.$nonce[1].'"', $attributes);
        }
    }

    public function test_hsts_is_not_sent_over_plain_http_outside_production(): void
    {
        $this->get('http://localhost/nova/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_bare_domain_redirects_to_the_portal(): void
    {
        $this->get('/')->assertRedirect('/nova');
    }
}
