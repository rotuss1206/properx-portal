<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Branded sign-in page: Nova's login screen restyled by public/portal assets.
 */
class LoginPageTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    public function test_the_login_page_loads_the_branded_assets_with_the_csp_nonce(): void
    {
        config(['portal.brand.support_url' => 'mailto:support@example.com']);

        $response = $this->get('/nova/login')->assertOk();

        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);

        $response
            ->assertSee('portal/login-page.css?v=', false)
            ->assertSee('portal/login-page.js?v=', false)
            ->assertSee('defer nonce="'.$nonce[1].'"', false)
            ->assertSee('mailto:support@example.com', false);
    }

    public function test_signed_in_pages_do_not_load_them(): void
    {
        $this->seedRoles();

        $this->actingAs($this->staff(Role::ExecutiveDirector))->get('/nova/user-security')->assertDontSee('portal/login-page', false);
    }

    public function test_the_assets_are_self_contained(): void
    {
        $css = file_get_contents(public_path('portal/login-page.css'));
        $js = file_get_contents(public_path('portal/login-page.js'));

        preg_match_all("~url\\('([^']+)'\\)~", $css, $fonts);

        $this->assertNotEmpty($fonts[1]);

        foreach ($fonts[1] as $font) {
            $this->assertFileExists(public_path('portal/'.$font));
        }

        // No external hosts (CSP), no inline styles, no runtime template compilation.
        $this->assertDoesNotMatchRegularExpression('~https?://(?!www\.w3\.org)~', $css.$js);
        $this->assertStringNotContainsString('style:', $js);
        $this->assertStringNotContainsString('template:', $js);
    }
}
