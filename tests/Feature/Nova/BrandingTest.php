<?php

namespace Tests\Feature\Nova;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    public function test_nova_uses_portal_branding(): void
    {
        $this->seedRoles();

        $config = $this->actingAs($this->member())
            ->get('/nova/dashboards/my-membership')
            ->assertOk()
            ->viewData('page')['props']['novaConfig'];

        $this->assertSame('ProPerx Alliance', config('nova.name'));
        $this->assertStringContainsString('aria-label="ProPerx Alliance"', $config['logo']);
        $this->assertSame('45, 108, 186', $config['brandColors']['500']);
        $this->assertStringContainsString('--colors-primary-500: 45, 108, 186', $config['brandColorsCSS']);

        $footer = $config['footer'];
        $this->assertStringContainsString('ProPerx Alliance', $footer);
        $this->assertStringNotContainsString('Laravel', $footer);
    }
}
