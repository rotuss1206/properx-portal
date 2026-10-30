<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\StaffAccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * app:assign-executive-director: the only way to give or take the Executive Director role.
 */
class AssignExecutiveDirectorTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Notification::fake();
    }

    public function test_creates_the_account_with_an_unknown_password_and_emails_the_link(): void
    {
        $this->artisan('app:assign-executive-director', ['email' => 'New.Director@Example.com', '--name' => 'New Director', '--force' => true])
            ->expectsOutputToContain('Created new.director@example.com as the Executive Director')
            ->assertSuccessful();

        $user = User::where('email', 'new.director@example.com')->sole();
        $this->assertSame([Role::ExecutiveDirector->value], $user->getRoleNames()->all());
        Notification::assertSentTo($user, StaffAccountCreated::class);

        $entry = AuditLog::where('action', 'executive_director_assigned')->sole();
        $this->assertSame($user->id, $entry->resource_id);
        $this->assertSame(['account_created' => true, 'via' => 'console'], $entry->details);
    }

    public function test_promotes_an_existing_staff_account_after_confirmation(): void
    {
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->artisan('app:assign-executive-director', ['email' => $coordinator->email])
            ->expectsConfirmation("Make {$coordinator->email} the Executive Director?", 'yes')
            ->assertSuccessful();

        $this->assertSame([Role::ExecutiveDirector->value], $coordinator->fresh()->getRoleNames()->all());
        Notification::assertNothingSent();
        $this->assertSame(['account_created' => false, 'via' => 'console'], AuditLog::where('action', 'executive_director_assigned')->sole()->details);
    }

    public function test_changes_nothing_without_confirmation(): void
    {
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->artisan('app:assign-executive-director', ['email' => $coordinator->email])
            ->expectsConfirmation("Make {$coordinator->email} the Executive Director?", 'no')
            ->assertFailed();

        $this->artisan('app:assign-executive-director', ['email' => 'nobody@example.com', '--name' => 'Nobody'])
            ->expectsConfirmation('Create a staff account for nobody@example.com and make it the Executive Director?', 'no')
            ->assertFailed();

        $this->assertFalse($coordinator->fresh()->isExecutiveDirector());
        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.com']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'executive_director_assigned']);
    }

    public function test_refuses_member_and_service_accounts_and_needs_a_name_for_new_ones(): void
    {
        $member = $this->member();

        $this->artisan('app:assign-executive-director', ['email' => $member->email, '--force' => true])->assertFailed();
        $this->artisan('app:assign-executive-director', ['email' => $this->apiService()->email, '--force' => true])->assertFailed();
        $this->artisan('app:assign-executive-director', ['email' => 'new@example.com', '--force' => true])
            ->expectsOutputToContain('--name')
            ->assertFailed();
        $this->artisan('app:assign-executive-director', ['email' => 'not-an-email', '--name' => 'X', '--force' => true])->assertFailed();

        $this->assertSame(0, User::role(Role::ExecutiveDirector->value)->count());
    }

    public function test_revokes_the_role_but_never_from_the_last_executive_director(): void
    {
        $first = $this->staff(Role::ExecutiveDirector);
        $second = $this->staff(Role::ExecutiveDirector);

        $this->artisan('app:assign-executive-director', ['email' => $second->email, '--revoke' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertSame([], $second->fresh()->getRoleNames()->all());
        $this->assertSame($second->id, AuditLog::where('action', 'executive_director_revoked')->sole()->resource_id);

        $this->artisan('app:assign-executive-director', ['email' => $first->email, '--revoke' => true, '--force' => true])
            ->expectsOutputToContain('last Executive Director')
            ->assertFailed();

        $this->assertTrue($first->fresh()->isExecutiveDirector());
    }

    public function test_revoke_asks_for_confirmation_and_needs_an_executive_director(): void
    {
        $this->staff(Role::ExecutiveDirector);
        $second = $this->staff(Role::ExecutiveDirector);
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->artisan('app:assign-executive-director', ['email' => $second->email, '--revoke' => true])
            ->expectsConfirmation("Revoke the Executive Director role from {$second->email}? The account will have no role and cannot sign in.", 'no')
            ->assertFailed();
        $this->artisan('app:assign-executive-director', ['email' => $coordinator->email, '--revoke' => true, '--force' => true])
            ->assertFailed();

        $this->assertTrue($second->fresh()->isExecutiveDirector());
        $this->assertTrue($coordinator->fresh()->hasRole(Role::MembershipCoordinator->value));
    }
}
