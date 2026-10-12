<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Kubernetes liveness / readiness probes. No RefreshDatabase: the probes need a
 * reachable database, not migrated tables, and one test swaps the connection.
 */
class HealthTest extends TestCase
{
    public function test_readiness_reports_each_check_without_details(): void
    {
        Storage::fake(config('portal.documents_disk'));

        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok', 'cache' => 'ok', 'queue' => 'ok', 'storage' => 'ok'],
            ]);
    }

    public function test_readiness_fails_with_503_and_hides_the_error(): void
    {
        Storage::shouldReceive('disk')->andThrow(new RuntimeException('connection to secret-account.blob.core.windows.net refused'));

        $response = $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.storage', 'fail')
            ->assertJsonPath('checks.database', 'ok');

        $this->assertStringNotContainsString('secret-account', $response->getContent());
    }

    public function test_database_outage_fails_readiness_but_not_liveness(): void
    {
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => base_path('missing-dir/nope.sqlite')],
            'database.default' => 'broken',
        ]);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.database', 'fail');

        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_probes_need_no_authentication_and_set_no_cookies(): void
    {
        Storage::fake(config('portal.documents_disk'));

        $response = $this->get('/api/health')->assertOk();

        $this->assertEmpty($response->headers->getCookies());
    }
}
