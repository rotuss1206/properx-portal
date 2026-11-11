<?php

namespace App\Http\Controllers\Join;

use App\Enums\InviteStatus;
use App\Http\Controllers\Controller;
use App\Member\ProfileRules;
use App\Models\AgencyInvitation;
use App\Models\MemberProfile;
use App\Services\Agencies\AgencyInvitations;
use App\Support\UsStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Nova\Nova;

/**
 * Accepting an agency invitation (Agency spec 7): the link's page sets the password and signs the
 * person in; RequireTwoFactor then runs the two-factor setup; last (not for the principal, who
 * gave them at enrollment) the profile check and the consents, after which the account is active
 * and the person lands in the member area. An expired, used or replaced link gets a page that
 * says so, with "Ask for a new invitation".
 */
class InvitationController extends Controller
{
    public function __construct(private readonly AgencyInvitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $link = AgencyInvitation::findByToken($token);

        if ($link === null || $link->memberProfile?->user === null) {
            return $this->unavailable(null, 'unknown');
        }

        if (! $link->isOpen()) {
            return $this->unavailable($link, $link->state());
        }

        return $this->page('Set up your account', 'join.invitation.password', [
            'token' => $token,
            'email' => $link->memberProfile->user->email,
            'expiresAt' => $link->expires_at,
        ]);
    }

    public function setPassword(Request $request, string $token): RedirectResponse|Response
    {
        $link = AgencyInvitation::findByToken($token);

        if ($link === null || ! $link->isOpen() || $link->memberProfile?->user === null) {
            return $this->unavailable($link, $link?->state() ?? 'unknown');
        }

        $request->validate(
            ['password' => ['required', 'string', 'confirmed', Password::default()]],
            ['password.required' => 'Choose a password.', 'password.confirmed' => "The two passwords don't match."],
        );

        $user = $this->invitations->setPassword($link, (string) $request->input('password'));

        // Whoever was signed in on this browser before, the invited person is now.
        if (Auth::check()) {
            Auth::logout();
        }

        $request->session()->invalidate();
        Auth::login($user); // RecordLogin: the setup that follows does not ask for the password again.
        $request->session()->regenerate();

        // RequireTwoFactor sends them through the two-factor setup first.
        return $user->memberProfile?->mustFinishInvitation()
            ? redirect()->route('join.invitation.complete')
            : redirect($this->home($request));
    }

    public function requestNew(Request $request, string $token): Response|RedirectResponse
    {
        $link = AgencyInvitation::findByToken($token);

        if ($link === null || $link->memberProfile === null) {
            return $this->unavailable(null, 'unknown');
        }

        if ($link->isOpen()) {
            return redirect()->route('join.invitation', $token);
        }

        $asked = $this->invitations->requestNew($link);

        return $this->unavailable($link, $link->state(), $asked ? 'asked' : 'asked_before');
    }

    /**
     * The last step: the profile (contacts; NPN and state for a licensed producer) and the consents.
     */
    public function completeForm(Request $request): Response|RedirectResponse
    {
        $profile = $this->pendingProfile($request);

        if ($profile === null) {
            return redirect($this->home($request));
        }

        return $this->page('Confirm your details', 'join.invitation.complete', [
            'profile' => $profile,
            'producer' => ! $profile->isAgencyEmployee(),
            'states' => UsStates::ALL,
            'consents' => config('portal.invitations.consents'),
        ]);
    }

    public function complete(Request $request): RedirectResponse
    {
        $profile = $this->pendingProfile($request);

        if ($profile === null) {
            return redirect($this->home($request));
        }

        $consents = array_keys((array) config('portal.invitations.consents'));
        $input = $request->only(['first_name', 'last_name', 'phone', 'npn', 'license_state']);
        $rules = ProfileRules::contact($profile, $input);
        unset($rules['address']);

        if (! $profile->isAgencyEmployee()) {
            $rules['npn'] = ProfileRules::business($profile, $input)['npn'] ?? [];
            $rules['npn'][0] = 'required';
            $rules['license_state'] = ['required', Rule::in(array_keys(UsStates::ALL))];
        }

        foreach ($consents as $key) {
            $rules["consents.{$key}"] = ['accepted'];
        }

        $validated = $request->validate($rules, ProfileRules::messages() + [
            'consents.*.accepted' => 'Please tick this box to continue.',
        ], ProfileRules::attributes());

        // MemberProfileObserver audits the fields that changed (as the member's own edit).
        $profile->fill(collect($validated)->except('consents')->all());
        $profile->save();

        $this->invitations->accept($profile, $consents);

        return redirect($this->home($request))->with('status', 'Welcome to ProPerX Alliance. Your account is set up.');
    }

    private function pendingProfile(Request $request): ?MemberProfile
    {
        $profile = $request->user()?->memberProfile;

        return $profile !== null && $profile->mustFinishInvitation() ? $profile : null;
    }

    private function home(Request $request): string
    {
        return config('portal.member_area.enabled') && $request->user()?->isMember() && ! $request->user()->isStaff()
            ? route('member.dashboard')
            : Nova::url('/');
    }

    /**
     * @param  'unknown'|'used'|'revoked'|'expired'|'open'  $state
     * @param  'asked'|'asked_before'|null  $asked
     */
    private function unavailable(?AgencyInvitation $link, string $state, ?string $asked = null): Response
    {
        $profile = $link?->memberProfile;

        return $this->page('Invitation link', 'join.invitation.unavailable', [
            'state' => $state,
            'token' => $link !== null && $profile !== null ? $this->tokenFor($link) : null,
            'active' => $profile?->invite_status === InviteStatus::AccountActive,
            'asked' => $asked,
        ], $state === 'open' ? Response::HTTP_OK : Response::HTTP_GONE);
    }

    /**
     * The page posts "Ask for a new invitation" back to the address it was opened with.
     */
    private function tokenFor(AgencyInvitation $link): ?string
    {
        $token = request()->route('token');

        return is_string($token) && AgencyInvitation::hash($token) === $link->token_hash ? $token : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(string $title, string $view, array $data, int $status = Response::HTTP_OK): Response
    {
        return response()->view('join.layout', [
            'title' => $title,
            'heading' => 'Your ProPerX Alliance membership',
            'slot' => new HtmlString(view($view, $data)->render()),
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
