<?php

namespace App\Providers;

use App\Auth\KeepConfirmedTwoFactorSecret;
use App\Auth\KeepRequiredTwoFactor;
use App\Auth\LoginRateLimiter;
use App\Auth\SignInResponse;
use App\Mail\AcsTransportFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\LoginRateLimiter as FortifyLoginRateLimiter;
use Laravel\Nova\Nova;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /** Livewire's JavaScript (the CSP-safe build, config/livewire.php csp_safe). */
    public const LIVEWIRE_SCRIPT_PATH = '/livewire/livewire.min.js';

    /** Livewire's update endpoint: every wire:click, wire:model.blur, "Continue". */
    public const LIVEWIRE_UPDATE_PATH = '/livewire/update';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 5 failed logins per 15 minutes per IP (Spec 8).
        $this->app->bind(FortifyLoginRateLimiter::class, LoginRateLimiter::class);

        $this->app->bind(DisableTwoFactorAuthentication::class, KeepRequiredTwoFactor::class);
        $this->app->bind(EnableTwoFactorAuthentication::class, KeepConfirmedTwoFactorSecret::class);

        // Extended, not bound: Nova binds its own responses later, while serving a request.
        $this->app->extend(LoginResponse::class, fn () => new SignInResponse);
        $this->app->extend(TwoFactorLoginResponse::class, fn () => new SignInResponse);
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

        // Invitation links (Agency spec 7): page loads and form posts per IP; "Ask for a new
        // invitation" has its own, tighter limit.
        RateLimiter::for('agency-invitation', function (Request $request) {
            return Limit::perMinute(config('portal.registration.page_requests_per_minute'))->by('invitation|'.$request->ip());
        });

        RateLimiter::for('agency-invitation-request', function (Request $request) {
            return Limit::perHour(config('portal.invitations.renewal_requests_per_hour_per_ip'))->by('invitation-request|'.$request->ip());
        });

        RateLimiter::for('stripe-webhook', function (Request $request) {
            return Limit::perMinute(config('services.stripe.webhook_requests_per_minute'))->by('stripe|'.$request->ip());
        });

        // Guessing the first code or the password: per person, with a message on the page.
        RateLimiter::for('two-factor-setup', function (Request $request) {
            return Limit::perMinute(5)->by('2fa-setup|'.$request->user()?->getKey())->response(
                fn () => back()->withErrors(['code' => 'Too many attempts. Wait a minute and try again.', 'password' => 'Too many attempts. Wait a minute and try again.'])
            );
        });

        RateLimiter::for('intake', function (Request $request) {
            return Limit::perMinute(config('portal.intake.requests_per_minute'))->by('intake|'.$request->ip());
        });

        RateLimiter::for('agency-handoff', function (Request $request) {
            return Limit::perMinute(config('portal.registration.handoff.requests_per_minute'))->by('agency-handoff|'.$request->ip());
        });

        RateLimiter::for('livewire', function (Request $request) {
            return Limit::perMinute(config('portal.registration.livewire_requests_per_minute'))->by($request->ip());
        });

        // Livewire's script and update routes on FIXED paths. Livewire 4's default paths are
        // /livewire-<hash of APP_KEY>/..., but the image caches routes at build time under a
        // throwaway APP_KEY (Dockerfile, route:cache), so pages rendered with the real key linked
        // to paths the cached routes did not have: livewire.min.js and every update were 404.
        // The upload and preview routes keep Livewire's paths; their URLs are generated from the
        // named (cached) routes, so they always agree.
        Livewire::setScriptRoute(fn (array $handle) => Route::get(self::LIVEWIRE_SCRIPT_PATH, $handle));

        // The update route also gets the throttle; Livewire itself adds the "web" group
        // (session, CSRF) and its header check.
        Livewire::setUpdateRoute(fn (array $handle) => Route::post(self::LIVEWIRE_UPDATE_PATH, $handle)
            ->middleware(['web', 'throttle:livewire'])
            ->name('portal.livewire.update'));

        // Nova's inline scripts carry the per-request CSP nonce (see SecurityHeaders).
        Nova::cspNonce(fn () => Vite::cspNonce());
    }
}
