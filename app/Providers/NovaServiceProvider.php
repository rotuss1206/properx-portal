<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Nova\Dashboards\Main;
use App\Nova\Dashboards\MyMembership;
use App\Support\MembershipBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Features;
use Laravel\Nova\Dashboard;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Nova;
use Laravel\Nova\NovaApplicationServiceProvider;
use Laravel\Nova\Tool;

class NovaServiceProvider extends NovaApplicationServiceProvider
{
    /**
     * Roles allowed into Nova. The API service account is deliberately absent.
     */
    private const NOVA_ROLES = [
        Role::ExecutiveDirector,
        Role::MembershipCoordinator,
        Role::ComplianceSpecialist,
        Role::Member,
    ];

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        Nova::initialPath(fn (Request $request) => $request->user()?->isMember()
            ? '/dashboards/my-membership'
            : '/dashboards/main');

        // Branding: replaces the default "Powered by Laravel Nova" footer.
        Nova::footer(fn (Request $request) => view('nova.footer')->render());

        // Past-due / inactive membership banner for members (Spec §7).
        Nova::serving(function (ServingNova $event) {
            Nova::provideToScript(['membershipBanner' => MembershipBanner::for($event->request->user())]);
            Nova::script('membership-banner', resource_path('js/nova/membership-banner.js'));
        });
    }

    /**
     * Register the configurations for Laravel Fortify.
     */
    protected function fortify(): void
    {
        $features = [
            Features::updatePasswords(),
            Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
        ];

        // Nova syncs these into Fortify only while serving Nova; routes are
        // registered earlier, so set them up front too (keeps passkeys off).
        // Login attempts are limited by App\Auth\LoginRateLimiter; the 2FA
        // challenge uses the "two-factor" limiter from AppServiceProvider.
        config([
            'fortify.features' => $features,
            'fortify.limiters.two-factor' => 'two-factor',
        ]);

        Nova::fortify()
            ->features($features)
            ->register();
    }

    /**
     * Register the Nova routes.
     */
    protected function routes(): void
    {
        Nova::routes()
            ->withAuthenticationRoutes(default: true)
            ->withPasswordResetRoutes()
            ->withoutEmailVerificationRoutes()
            ->register();
    }

    /**
     * Use the viewNova gate in every environment, including local
     * (the parent lets everyone in when APP_ENV=local).
     */
    protected function authorization(): void
    {
        Nova::auth(static fn (Request $request) => Gate::check('viewNova', [Nova::user($request)]));
    }

    /**
     * Register the Nova gate.
     */
    protected function gate(): void
    {
        Gate::define('viewNova', function (User $user) {
            return $user->hasAnyRole(array_map(fn (Role $role) => $role->value, self::NOVA_ROLES));
        });
    }

    /**
     * Get the dashboards that should be listed in the Nova sidebar.
     *
     * @return array<int, Dashboard>
     */
    protected function dashboards(): array
    {
        return [
            Main::make()->canSee(fn (Request $request) => $request->user()?->isStaff() ?? false),
            MyMembership::make()->canSee(fn (Request $request) => $request->user()?->isMember() ?? false),
        ];
    }

    /**
     * Get the tools that should be listed in the Nova sidebar.
     *
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        parent::register();

        //
    }
}
