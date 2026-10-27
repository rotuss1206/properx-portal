<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * php artisan app:password-link {email}: the "Forgot your password?" link, printed for when
 * the reset email cannot be delivered.
 */
class CreatePasswordLinkTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    /**
     * @return array{0: int, 1: string, 2: ?string} exit code, output, token
     */
    private function createLink(string $email): array
    {
        $exitCode = Artisan::call('app:password-link', ['email' => $email]);
        $output = Artisan::output();
        preg_match('#/nova/password/reset/([A-Za-z0-9]+)#', $output, $match);

        return [$exitCode, $output, $match[1] ?? null];
    }

    private function resetWith(User $user, string $token, string $password): TestResponse
    {
        return $this->postJson('/nova/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_prints_a_one_time_link_that_sets_the_password(): void
    {
        $member = $this->member(withTwoFactor: false);

        [$exitCode, $output, $token] = $this->createLink($member->email);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(route('nova.pages.password.reset', ['token' => $token]), $output);
        $this->get(route('nova.pages.password.reset', ['token' => $token]))->assertOk();

        $this->resetWith($member, $token, 'A-new-Passw0rd!')->assertSuccessful();
        $this->assertTrue(Hash::check('A-new-Passw0rd!', $member->fresh()->password));

        // Single use.
        $this->resetWith($member, $token, 'Another-Passw0rd!')->assertUnprocessable();
        $this->assertTrue(Hash::check('A-new-Passw0rd!', $member->fresh()->password));
    }

    public function test_a_new_link_replaces_the_previous_one(): void
    {
        $member = $this->member(withTwoFactor: false);

        [, , $first] = $this->createLink($member->email);
        [, , $second] = $this->createLink($member->email);

        $this->resetWith($member, $first, 'A-new-Passw0rd!')->assertUnprocessable();
        $this->resetWith($member, $second, 'A-new-Passw0rd!')->assertSuccessful();
    }

    public function test_is_audited_but_not_logged(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        Log::spy();

        $this->createLink($director->email);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_link_created',
            'resource_type' => $director->getMorphClass(),
            'resource_id' => $director->id,
        ]);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    public function test_refuses_unknown_accounts_and_the_api_service_account(): void
    {
        foreach (['nobody@example.com', $this->apiService()->email] as $email) {
            [$exitCode, $output, $token] = $this->createLink($email);

            $this->assertSame(1, $exitCode);
            $this->assertNull($token);
            $this->assertStringNotContainsString($email, $output);
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'password_link_created')->count());
    }
}
