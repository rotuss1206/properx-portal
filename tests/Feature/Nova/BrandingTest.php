<?php

namespace Tests\Feature\Nova;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    public function test_visible_text_spells_the_brand_with_a_capital_x(): void
    {
        $roots = ['app', 'config', 'lang', 'resources/views', 'resources/js', 'public/portal'];
        $found = [];

        foreach ($roots as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (in_array($file->getExtension(), ['php', 'js', 'css', 'json', 'svg'], true) && str_contains((string) file_get_contents($file->getPathname()), 'ProPerx ')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $found);
        $this->assertStringContainsString('Enter the email address this message was sent to', (string) file_get_contents(public_path('portal/login-page.js')));
    }

    public function test_the_white_logo_is_the_colour_logo_repainted(): void
    {
        $paths = fn (string $file) => preg_match_all('/\sd="([^"]+)"/', (string) file_get_contents(public_path('portal/img/'.$file)), $found) ? $found[1] : [];

        $this->assertNotSame([], $paths('logo-white.svg'));
        $this->assertSame($paths('logo-color.svg'), $paths('logo-white.svg'));

        $white = (string) file_get_contents(public_path('portal/img/logo-white.svg'));
        preg_match_all('/fill="([^"]+)"/', $white, $fills);

        $this->assertSame(['none', '#FFFFFF'], array_values(array_unique($fills[1])));
        $this->assertStringNotContainsString('Gradient', $white);
    }

    public function test_each_screen_uses_the_logo_for_its_background(): void
    {
        $this->assertStringContainsString('img/logo-white.svg', (string) file_get_contents(public_path('portal/login-page.js')));
        $this->assertStringNotContainsString('background', $this->cssRule(public_path('portal/login-page.css'), '.ppx-login__logo'));
        $this->assertStringContainsString('logo-color.svg', (string) file_get_contents(resource_path('views/components/security/setup-layout.blade.php')));
        $this->assertStringContainsString('logo-color.svg', (string) file_get_contents(resource_path('views/certificates/layout.blade.php')));
        $this->assertStringContainsString('logo-color.svg', (string) file_get_contents(resource_path('views/join/layout.blade.php')));
    }

    public function test_the_drawer_artwork_is_anchored_to_the_drawer_not_the_shorter_menu(): void
    {
        $css = resource_path('css/nova/sidebar.css');

        $this->assertStringContainsString('position: static', $this->cssRule($css, '.ppx-shell .ppx-drawer .ppx-sidebar--responsive'));
        $this->assertStringContainsString('bottom: 0', $this->cssRule($css, '.ppx-shell .ppx-drawer .ppx-sidebar--responsive .ppx-sidebar__decor'));
    }

    private function cssRule(string $file, string $selector): string
    {
        preg_match('/^'.preg_quote($selector, '/').' \{(.*?)\}/ms', (string) file_get_contents($file), $rule);

        return $rule[1] ?? '';
    }

    public function test_nova_uses_portal_branding(): void
    {
        $this->seedRoles();

        $config = $this->actingAs($this->member())
            ->get('/nova/dashboards/my-membership')
            ->assertOk()
            ->viewData('page')['props']['novaConfig'];

        $this->assertSame('ProPerX Alliance', config('nova.name'));
        $this->assertStringContainsString('aria-label="ProPerX Alliance"', $config['logo']);
        $this->assertSame('45, 108, 186', $config['brandColors']['500']);
        $this->assertStringContainsString('--colors-primary-500: 45, 108, 186', $config['brandColorsCSS']);

        $footer = $config['footer'];
        $this->assertStringContainsString('ProPerX Alliance', $footer);
        $this->assertStringNotContainsString('Laravel', $footer);
    }
}
