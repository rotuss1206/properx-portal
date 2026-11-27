<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Livewire's script and update routes do not depend on APP_KEY. The image caches routes at build
 * time under a throwaway key (Dockerfile, route:cache); Livewire 4's default /livewire-<hash of
 * APP_KEY>/ paths then differed from the ones pages linked to, and /join/agency got 404s for
 * livewire.min.js and every "Continue".
 */
class LivewireEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_script_and_update_routes_are_on_fixed_paths(): void
    {
        $this->assertSame(AppServiceProvider::LIVEWIRE_UPDATE_PATH, Livewire::getUpdateUri());

        $script = $this->get(AppServiceProvider::LIVEWIRE_SCRIPT_PATH)->assertOk();

        $this->assertStringContainsString('javascript', (string) $script->headers->get('Content-Type'));
    }

    public function test_the_enrollment_page_links_to_the_fixed_paths(): void
    {
        config(['portal.registration.enabled' => true]);

        $html = $this->get('/join/agency')
            ->assertOk()
            ->assertSee(AppServiceProvider::LIVEWIRE_SCRIPT_PATH, false)
            ->assertSee(AppServiceProvider::LIVEWIRE_UPDATE_PATH, false)
            ->getContent();

        // The script and the updates never use the /livewire-<hash> prefix. Livewire still prints
        // that prefix as data-module-url, the base for component JS modules, which no component
        // here has (CSP-safe mode); it is never requested.
        $this->assertDoesNotMatchRegularExpression('#src="[^"]*/livewire-[0-9a-f]+/#', $html);
        $this->assertDoesNotMatchRegularExpression('#data-update-uri="[^"]*/livewire-[0-9a-f]+/#', $html);
    }
}
