<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Services\ApiKeys\ApiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * API Service account authentication by API key (Spec 3.7, 8).
 */
class ApiKeyTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_only_a_hash_of_the_key_is_stored(): void
    {
        [$key, $plain] = app(ApiKeyManager::class)->issue($this->apiService());

        $this->assertStringStartsWith(ApiKeyManager::PREFIX, $plain);
        $this->assertSame(hash('sha256', $plain), $key->key_hash);
        $this->assertSame(substr($plain, -4), $key->last_four);
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $plain]);
        $this->assertArrayNotHasKey('key_hash', $key->toArray());
    }

    public function test_valid_key_authenticates_machine_calls(): void
    {
        $service = $this->apiService();
        [, $plain] = app(ApiKeyManager::class)->issue($service);

        $this->withToken($plain)
            ->getJson(route('api.service.whoami'))
            ->assertOk()
            ->assertJsonPath('account', $service->name)
            ->assertJsonFragment(['webhooks.stripe.handle']);

        $this->withHeader('X-Api-Key', $plain)->getJson(route('api.service.whoami'))->assertOk();

        $this->assertNotNull(ApiKey::sole()->last_used_at);
    }

    public function test_missing_or_wrong_keys_are_rejected(): void
    {
        app(ApiKeyManager::class)->issue($this->apiService());

        $this->getJson(route('api.service.whoami'))->assertUnauthorized();
        $this->withToken('ppx_wrong')->getJson(route('api.service.whoami'))->assertUnauthorized();
    }

    public function test_revoked_and_expired_keys_are_rejected(): void
    {
        $manager = app(ApiKeyManager::class);
        $service = $this->apiService();

        [$revoked, $revokedPlain] = $manager->issue($service, 'revoked');
        $revoked->update(['revoked_at' => now()]);

        [$expired, $expiredPlain] = $manager->issue($service, 'expired');
        $expired->update(['expires_at' => now()->subMinute()]);

        $this->withToken($revokedPlain)->getJson(route('api.service.whoami'))->assertUnauthorized();
        $this->withToken($expiredPlain)->getJson(route('api.service.whoami'))->assertUnauthorized();
    }

    public function test_keys_only_work_for_the_api_service_role(): void
    {
        [, $plain] = app(ApiKeyManager::class)->issue($this->staff(Role::ExecutiveDirector));

        $this->withToken($plain)->getJson(route('api.service.whoami'))->assertUnauthorized();
    }

    public function test_api_key_does_not_open_the_web_portal(): void
    {
        [, $plain] = app(ApiKeyManager::class)->issue($this->apiService());

        $this->withToken($plain)->getJson('/nova-api/member-profiles')->assertUnauthorized();
    }

    public function test_rotation_issues_a_new_key_keeps_the_old_one_during_overlap_and_is_audited(): void
    {
        $service = $this->apiService();
        [, $oldKey] = app(ApiKeyManager::class)->issue($service);

        $this->assertSame(0, Artisan::call('app:api-key:rotate', ['--overlap' => 24]));

        $newKey = collect(explode("\n", Artisan::output()))
            ->map(fn (string $line) => trim($line))
            ->first(fn (string $line) => str_starts_with($line, ApiKeyManager::PREFIX));

        $this->assertNotNull($newKey);
        $this->assertNotSame($oldKey, $newKey);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key_rotated', 'resource_id' => $service->id]);

        // Both work during the overlap window.
        $this->withToken($oldKey)->getJson(route('api.service.whoami'))->assertOk();
        $this->withToken($newKey)->getJson(route('api.service.whoami'))->assertOk();

        $this->travel(25)->hours();

        $this->withToken($oldKey)->getJson(route('api.service.whoami'))->assertUnauthorized();
        $this->withToken($newKey)->getJson(route('api.service.whoami'))->assertOk();
    }

    public function test_rotation_without_previous_key_just_issues_one(): void
    {
        $this->apiService();

        $this->artisan('app:api-key:rotate')->assertSuccessful();

        $this->assertSame(1, ApiKey::count());
        $this->assertSame(1, AuditLog::where('action', 'api_key_rotated')->count());
    }

    public function test_rotation_fails_without_an_api_service_account(): void
    {
        $this->artisan('app:api-key:rotate')->assertFailed();
    }
}
