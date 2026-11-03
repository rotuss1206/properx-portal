<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Nova\Nova;

/**
 * The first-time two-factor setup: what it is, the QR code, the first code, the recovery codes.
 * Runs on Fortify's own actions. The password is not asked again right after signing in, which has
 * just proved it; later in the session it is. Until the recovery codes are acknowledged
 * App\Http\Middleware\RequireTwoFactor keeps the rest of the portal closed.
 */
class TwoFactorSetupController extends Controller
{
    /** Time of the last password sign-in (App\Listeners\RecordLogin). */
    public const FRESH_LOGIN_KEY = 'two_factor_setup.login_at';

    /** Set when the code is confirmed, cleared once the recovery codes are acknowledged. */
    public const PENDING_CODES_KEY = 'two_factor_setup.pending_codes';

    private const FRESH_LOGIN_SECONDS = 900;

    public function intro(Request $request): View|RedirectResponse
    {
        return $this->redirectIfDone($request) ?? view('security.two-factor.intro', [
            'askPassword' => ! $this->passwordIsFresh($request),
        ]);
    }

    public function start(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        if ($redirect = $this->redirectIfDone($request)) {
            return $redirect;
        }

        if (! $this->passwordIsFresh($request)) {
            $request->validate(['password' => ['required', 'string', 'current_password']]);
            $request->session()->passwordConfirmed();
        }

        $user = $request->user();

        // An unfinished earlier attempt keeps its secret: it may already be in the person's app.
        if ($user->two_factor_secret === null) {
            $enable($user);
            AuditLog::record('mfa_setup_started', $user);
        }

        return redirect()->route('security.two-factor.scan');
    }

    public function scan(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectIfNotReady($request)) {
            return $redirect;
        }

        $user = $request->user();

        return response()->view('security.two-factor.scan', [
            'qrCode' => $user->twoFactorQrCodeSvg(),
            'setupKey' => implode(' ', str_split(decrypt($user->two_factor_secret), 4)),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function verifyForm(Request $request): View|RedirectResponse
    {
        return $this->redirectIfNotReady($request) ?? view('security.two-factor.verify');
    }

    public function verify(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        if ($redirect = $this->redirectIfNotReady($request)) {
            return $redirect;
        }

        $code = preg_replace('/\s+/', '', (string) $request->input('code'));

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return back()->withErrors(['code' => 'Enter the 6-digit code shown in your app.']);
        }

        $user = $request->user();

        try {
            $confirm($user, $code);
        } catch (ValidationException) {
            AuditLog::record('mfa_code_rejected', $user);

            return back()->withErrors(['code' => "That code didn't work. Codes change every 30 seconds: enter the one your app shows now, and check that your device's clock is right."]);
        }

        $request->session()->put(self::PENDING_CODES_KEY, true);

        return redirect()->route('security.two-factor.recovery');
    }

    public function recovery(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->get(self::PENDING_CODES_KEY)) {
            return redirect()->route('security.two-factor');
        }

        return response()->view('security.two-factor.recovery', [
            'codes' => $request->user()->recoveryCodes(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function download(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->get(self::PENDING_CODES_KEY)) {
            return redirect()->route('security.two-factor');
        }

        return response(implode("\n", $request->user()->recoveryCodes())."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="properx-recovery-codes.txt"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function finish(Request $request): RedirectResponse
    {
        if (! $request->session()->get(self::PENDING_CODES_KEY)) {
            return redirect()->route('security.two-factor');
        }

        $request->validate(['saved' => ['accepted']], ['saved.accepted' => 'Tick the box once you have saved your recovery codes.']);

        $request->session()->forget([self::PENDING_CODES_KEY, self::FRESH_LOGIN_KEY]);
        AuditLog::record('mfa_recovery_codes_saved', $request->user());

        return redirect($this->home($request->user()));
    }

    private function passwordIsFresh(Request $request): bool
    {
        $session = $request->session();
        $signedIn = (int) $session->get(self::FRESH_LOGIN_KEY, 0);
        $confirmed = (int) $session->get('auth.password_confirmed_at', 0);

        return time() - $signedIn < self::FRESH_LOGIN_SECONDS
            || time() - $confirmed < (int) config('auth.password_timeout', 10800);
    }

    private function redirectIfDone(Request $request): ?RedirectResponse
    {
        if ($request->session()->get(self::PENDING_CODES_KEY)) {
            return redirect()->route('security.two-factor.recovery');
        }

        return $request->user()->hasConfirmedTwoFactor() ? redirect($this->home($request->user())) : null;
    }

    /**
     * The QR code and the secret show only once setup has started and the password is fresh.
     */
    private function redirectIfNotReady(Request $request): ?RedirectResponse
    {
        if ($redirect = $this->redirectIfDone($request)) {
            return $redirect;
        }

        return $request->user()->two_factor_secret === null || ! $this->passwordIsFresh($request)
            ? redirect()->route('security.two-factor')
            : null;
    }

    private function home(User $user): string
    {
        return config('portal.member_area.enabled') && $user->isMember() && ! $user->isStaff()
            ? route('member.dashboard')
            : Nova::url('/');
    }
}
