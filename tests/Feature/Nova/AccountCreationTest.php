<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Accounts created through Nova always get a role and can sign in with the password set.
 */
class AccountCreationTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_staff_created_in_nova_gets_the_role_and_can_sign_in(): void
    {
        $this->actingAs($this->staff(Role::ExecutiveDirector))
            ->postJson('/nova-api/staff', [
                'name' => 'New Coordinator',
                'email' => 'new.coordinator@example.com',
                'role' => Role::MembershipCoordinator->value,
                'password' => self::PASSWORD,
            ])
            ->assertCreated();

        $user = User::where('email', 'new.coordinator@example.com')->sole();
        $this->assertSame([Role::MembershipCoordinator->value], $user->getRoleNames()->all());

        $this->post('/nova/logout');
        $this->postJson('/nova/login', ['email' => 'new.coordinator@example.com', 'password' => self::PASSWORD])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidRoles(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'member role' => [Role::Member->value],
            'api service role' => [Role::ApiService->value],
            'unknown role' => ['Super Admin'],
            'case mismatch' => ['executive director'],
        ];
    }

    #[DataProvider('invalidRoles')]
    public function test_staff_cannot_be_created_without_a_valid_staff_role(mixed $role): void
    {
        $this->actingAs($this->staff(Role::ExecutiveDirector))
            ->postJson('/nova-api/staff', array_filter([
                'name' => 'No Role',
                'email' => 'no.role@example.com',
                'role' => $role,
                'password' => self::PASSWORD,
            ], fn ($value) => $value !== null))
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'no.role@example.com']);
    }

    public function test_member_account_created_in_nova_gets_the_member_role_and_can_sign_in(): void
    {
        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/members', [
                'name' => 'New Member',
                'email' => 'new.member@example.com',
                'password' => self::PASSWORD,
            ])
            ->assertCreated();

        $user = User::where('email', 'new.member@example.com')->sole();
        $this->assertSame([Role::Member->value], $user->getRoleNames()->all());

        $this->post('/nova/logout');
        $this->postJson('/nova/login', ['email' => 'new.member@example.com', 'password' => self::PASSWORD])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_no_user_created_through_nova_is_left_without_a_role(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);

        $this->actingAs($director)->postJson('/nova-api/staff', [
            'name' => 'A', 'email' => 'a@example.com', 'role' => Role::ComplianceSpecialist->value, 'password' => self::PASSWORD,
        ])->assertCreated();
        $this->postJson('/nova-api/members', [
            'name' => 'B', 'email' => 'b@example.com', 'password' => self::PASSWORD,
        ])->assertCreated();
        $this->postJson('/nova-api/staff', [
            'name' => 'C', 'email' => 'c@example.com', 'role' => 'Member', 'password' => self::PASSWORD,
        ])->assertUnprocessable();

        $this->assertSame(0, User::doesntHave('roles')->count());
    }
}
