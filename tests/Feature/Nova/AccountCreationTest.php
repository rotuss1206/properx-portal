<?php

namespace Tests\Feature\Nova;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\StaffAccountCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Accounts created through Nova always get a role. Staff set their own password from the
 * emailed link; members get the password typed by staff.
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

    public function test_staff_created_in_nova_gets_the_role_and_sets_the_password_from_the_email(): void
    {
        Notification::fake();

        $this->actingAs($this->staff(Role::ExecutiveDirector))
            ->postJson('/nova-api/staff', [
                'name' => 'New Coordinator',
                'email' => 'new.coordinator@example.com',
                'role' => Role::MembershipCoordinator->value,
                'password' => self::PASSWORD, // no such field any more: ignored
            ])
            ->assertCreated();

        $user = User::where('email', 'new.coordinator@example.com')->sole();
        $this->assertSame([Role::MembershipCoordinator->value], $user->getRoleNames()->all());

        $this->post('/nova/logout');
        $this->postJson('/nova/login', ['email' => 'new.coordinator@example.com', 'password' => self::PASSWORD])->assertUnprocessable();

        $url = null;
        Notification::assertSentTo($user, StaffAccountCreated::class, function (StaffAccountCreated $notification) use ($user, &$url) {
            $mail = $notification->toMail($user);
            $url = $mail->actionUrl;

            return ! str_contains(implode(' ', $mail->introLines), 'New Coordinator'); // no name in the body
        });

        $this->assertStringContainsString('/nova/password/reset/', $url);
        $token = basename((string) parse_url($url, PHP_URL_PATH));

        $this->postJson('/nova/password/reset', [
            'token' => $token,
            'email' => 'new.coordinator@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk();

        $this->post('/nova/logout');
        $this->postJson('/nova/login', ['email' => 'new.coordinator@example.com', 'password' => self::PASSWORD])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_staff_form_has_no_password_field_and_offers_no_executive_director_role(): void
    {
        $fields = collect($this->actingAs($this->staff(Role::ExecutiveDirector))
            ->getJson('/nova-api/staff/creation-fields')
            ->assertOk()
            ->json('fields'));

        $this->assertNull($fields->firstWhere('attribute', 'password'));
        $this->assertSame(
            [Role::MembershipCoordinator->value, Role::ComplianceSpecialist->value],
            array_column($fields->firstWhere('attribute', 'role')['options'], 'value'),
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidRoles(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'executive director role (forged request)' => [Role::ExecutiveDirector->value],
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
            ], fn ($value) => $value !== null))
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'no.role@example.com']);
    }

    public function test_a_forged_update_cannot_make_anyone_executive_director(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $coordinator = $this->staff(Role::MembershipCoordinator);

        $this->actingAs($director)
            ->putJson("/nova-api/staff/{$coordinator->id}", [
                'name' => $coordinator->name,
                'email' => $coordinator->email,
                'role' => Role::ExecutiveDirector->value,
            ])
            ->assertJsonValidationErrors('role');

        $this->assertSame([Role::MembershipCoordinator->value], $coordinator->fresh()->getRoleNames()->all());
        $this->assertFalse($director->can('assignRole', [$coordinator, Role::ExecutiveDirector->value]));
        $this->assertTrue($director->can('assignRole', [$coordinator, Role::ComplianceSpecialist->value]));
    }

    public function test_an_executive_director_keeps_the_role_whatever_nova_receives(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $other = $this->staff(Role::ExecutiveDirector);

        foreach ([$director, $other] as $target) {
            $this->actingAs($director)
                ->putJson("/nova-api/staff/{$target->id}", [
                    'name' => 'Renamed',
                    'email' => $target->email,
                    'role' => Role::ComplianceSpecialist->value,
                ])
                ->assertOk();

            $target->refresh();
            $this->assertSame('Renamed', $target->name);
            $this->assertSame([Role::ExecutiveDirector->value], $target->getRoleNames()->all());
            $this->assertFalse($director->can('assignRole', [$target, Role::ComplianceSpecialist->value]));
        }
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
        Notification::fake();
        $director = $this->staff(Role::ExecutiveDirector);

        $this->actingAs($director)->postJson('/nova-api/staff', [
            'name' => 'A', 'email' => 'a@example.com', 'role' => Role::ComplianceSpecialist->value,
        ])->assertCreated();
        $this->postJson('/nova-api/members', [
            'name' => 'B', 'email' => 'b@example.com', 'password' => self::PASSWORD,
        ])->assertCreated();
        $this->postJson('/nova-api/staff', [
            'name' => 'C', 'email' => 'c@example.com', 'role' => 'Member',
        ])->assertUnprocessable();

        $this->assertSame(0, User::doesntHave('roles')->count());
    }
}
