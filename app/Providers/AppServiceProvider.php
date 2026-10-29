<?php

namespace App\Providers;

use App\Auth\LoginRateLimiter;
use App\Mail\AcsTransportFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\LoginRateLimiter as FortifyLoginRateLimiter;
use Laravel\Nova\Nova;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 5 failed logins per 15 minutes per IP (Spec 8).
        $this->app->bind(FortifyLoginRateLimiter::class, LoginRateLimiter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mail goes through Azure Communication Services only (KEENEY platform rule).
        Mail::extend('azure', fn (array $config) => AcsTransportFactory::fromConnectionString($config['connection_string'] ?? null));

        RateLimiter::for('certificate-verification', function (Request $request) {
            return Limit::perMinute(config('portal.verification.requests_per_minute'))->by($request->ip());
        });

        // Guessing TOTP codes: 5 tries per minute per pending login.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by('2fa|'.($request->session()->get('login.id') ?? $request->ip()));
        });

        // Public agency enrollment: page loads and Livewire round trips, per IP.
        RateLimiter::for('agency-registration', function (Request $request) {
            return Limit::perMinute(config('portal.registration.page_requests_per_minute'))->by($request->ip());
        });

        RateLimiter::for('stripe-webhook', function (Request $request) {
            return Limit::perMinute(config('services.stripe.webhook_requests_per_minute'))->by('stripe|'.$request->ip());
        });

        RateLimiter::for('livewire', function (Request $request) {
            return Limit::perMinute(config('portal.registration.livewire_requests_per_minute'))->by($request->ip());
        });

        // Replaces Livewire's default update route (same path) to add the throttle; Livewire
        // itself adds the "web" group (session, CSRF) and its header check.
        Livewire::setUpdateRoute(fn (array $handle, string $path) => Route::post($path, $handle)
            ->middleware(['web', 'throttle:livewire'])
            ->name('portal.livewire.update'));

        // Nova's inline scripts carry the per-request CSP nonce (see SecurityHeaders).
        Nova::cspNonce(fn () => Vite::cspNonce());
    }
}
