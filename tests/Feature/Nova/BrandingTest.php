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
