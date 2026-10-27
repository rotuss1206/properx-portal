<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\LibraryDocument;
use App\Models\User;
use App\Services\ServiceAccount;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_adds_reference_data_only(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Role::query()->count());
        $this->assertSame(0, Agency::query()->count());

        // The only user is the API Service system account (the Stripe webhook's actor).
        $service = User::query()->sole();
        $this->assertTrue($service->is(app(ServiceAccount::class)->user()));
        $this->assertTrue($service->checkPermissionTo('webhooks.stripe.handle'));
        $this->assertFalse(Gate::forUser($service)->allows('viewNova'));
    }

    public function test_database_seeder_keeps_an_existing_service_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->count());
    }

    public function test_demo_seeder_refuses_to_run_unless_allowed(): void
    {
        config(['portal.demo_seed_allowed' => false]);

        $this->expectException(RuntimeException::class);

        try {
            $this->seed(DemoSeeder::class);
        } finally {
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_demo_seeder_adds_fictional_staff_members_agencies_and_documents(): void
    {
        config(['portal.demo_seed_allowed' => true]);

        $this->seed(DemoSeeder::class);

        $this->assertNotNull(User::query()->firstWhere('email', 'director@properx.test'));
        $this->assertNotNull(User::query()->firstWhere('email', 'member01@properx.test'));
        $this->assertNotNull(User::query()->firstWhere('email', 'principal01@properx.test'));
        $this->assertSame(3, Agency::query()->count());
        $this->assertGreaterThan(0, LibraryDocument::query()->count());
    }
}
