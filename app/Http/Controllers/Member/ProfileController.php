<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\Overview;
use App\Member\ProfilePage;
use App\Member\ProfileRules;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\NotificationSetting;
use App\Nova\Menu\SidebarMenu;
use App\Support\UsStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's profile page: each section is edited on its own, in place (?edit=section), by a
 * plain form. No Livewire, so phone numbers, addresses and passwords never travel in a component
 * snapshot, and the page works the same without JavaScript. MemberProfileObserver and UserObserver
 * audit the changes (field names only); MemberProfile puts changed license data back to review.
 */
class ProfileController extends Controller
{
    /** Until when the new recovery codes can be downloaded (a timestamp in the session). */
    private const CODES_KEY = 'member.profile.recovery_codes_until';

    private const CODES_SECONDS = 600;

    public function show(Request $request): Response
    {
        // The reminder email's link: an anchor would not survive signing in, this query does.
        if ($request->query(SidebarMenu::SECTION) === 'notifications') {
            return redirect(route('member.profile').'#notifications');
        }

        $user = $request->user();
        $page = ProfilePage::for($user);
        $profile = $page->profile();

        return response()->view('member.profile', [
            'page' => 'profile',
            'title' => 'Profile',
            'profile' => $page,
            'member' => $profile,
            'email' => $user->email,
            'membership' => Overview::for($user)->membership(),
            'editing' => $page->editing($request->query('edit')),
            'states' => UsStates::ALL,
            'newCodes' => $request->session()->get('recovery_codes_new') ? $user->recoveryCodes() : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function updateContact(Request $request): RedirectResponse
    {
        $profile = $this->editableProfile($request, 'contact');

        $profile->fill($this->validated($request, 'contact', ProfileRules::contact($profile, $request->all())))->save();

        return $this->saved('contact', 'Contact information saved.');
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $profile = $this->editableProfile($request, 'business');

        $profile->fill($this->validated($request, 'business', ProfileRules::business($profile, $request->all())));

        return $this->saveAfterReviewWarning($request, $profile, 'business', 'Business details saved.');
    }

    public function updateLicense(Request $request): RedirectResponse
    {
        $profile = $this->editableProfile($request, 'license');
        $validated = $this->validated($request, 'license', ProfileRules::license($profile, $request->all()));

        if (isset($validated['license_type'])) {
            $validated['license_type'] = $profile->licenseTypeValue($validated['license_type']);
        }

        $profile->fill($validated);

        return $this->saveAfterReviewWarning($request, $profile, 'license', 'License information saved.');
    }

    /**
     * Two steps when the change would send the license back to review (the NPN is in Business, the
     * rest in License): the form comes back with the warning, and only "Save and send for review"
     * stores it.
     */
    private function saveAfterReviewWarning(Request $request, MemberProfile $profile, string $section, string $message): RedirectResponse
    {
        $returnsToReview = $profile->isDirty(MemberProfile::LICENSE_REVIEW_FIELDS)
            && ProfilePage::for($request->user())->licenseCanReturnToReview();

        if ($returnsToReview && ! $request->boolean('confirm_review')) {
            return redirect($this->editUrl($section))->withInput()->with('license_review_warning', true);
        }

        $profile->save();

        return $this->saved($section, $returnsToReview ? $message.' ProPerX will review your license again.' : $message);
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $user = $request->user();
        Gate::authorize('manageFor', [NotificationSetting::class, $user]);

        $changes = [];

        foreach (ProfilePage::for($user)->notificationSettings() as ['category' => $category, 'enabled' => $enabled]) {
            $wanted = $request->boolean($category->value);

            if ($wanted !== $enabled) {
                $user->notificationSettings()->updateOrCreate(['category' => $category], ['enabled' => $wanted]);
                $changes[$wanted ? 'enabled' : 'disabled'][] = $category->value;
            }
        }

        // Category names and on/off only.
        if ($changes !== []) {
            AuditLog::record('notification_settings_updated', $user, details: $changes);
        }

        return redirect(route('member.profile').'#notifications')
            ->with('profile_saved', 'notifications')
            ->with('status', 'Notification preferences saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $this->validated($request, 'security', ProfileRules::password());
        $user = $request->user();

        $user->forceFill(['password' => $validated['password']])->save();

        // Keeps this session signed in (Fortify's own password controller does the same).
        if ($request->hasSession()) {
            $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->getAuthPassword());
        }

        return $this->saved('security', 'Password changed.');
    }

    /**
     * Two-factor authentication itself cannot be turned off here: it is mandatory.
     */
    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasConfirmedTwoFactor(), Response::HTTP_FORBIDDEN);

        $this->validated($request, 'security', ['current_password' => ['required', 'string', 'current_password']], 'recoveryCodes');

        $generate($user);
        AuditLog::record('mfa_recovery_codes_regenerated', $user);

        $request->session()->put(self::CODES_KEY, time() + self::CODES_SECONDS);

        return redirect($this->editUrl('security'))
            ->with('recovery_codes_new', true)
            ->with('profile_saved', 'security')
            ->with('status', 'New recovery codes created. The old ones no longer work.');
    }

    public function downloadRecoveryCodes(Request $request): Response
    {
        abort_unless((int) $request->session()->get(self::CODES_KEY, 0) > time(), Response::HTTP_NOT_FOUND);

        return response(implode("\n", $request->user()->recoveryCodes())."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="properx-recovery-codes.txt"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function editableProfile(Request $request, string $section): MemberProfile
    {
        $page = ProfilePage::for($request->user());
        $profile = $page->profile();

        abort_if($profile === null, Response::HTTP_NOT_FOUND);
        Gate::authorize('update', $profile);
        abort_unless(in_array($section, $page->editableSections(), true), Response::HTTP_FORBIDDEN);

        return $profile;
    }

    /**
     * Errors lead back to the same section, still open.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $section, array $rules, string $errorBag = 'default'): array
    {
        $validator = Validator::make($request->all(), $rules, ProfileRules::messages(), ProfileRules::attributes());

        try {
            return $validator->validateWithBag($errorBag);
        } catch (ValidationException $e) {
            throw $e->redirectTo($this->editUrl($section));
        }
    }

    private function saved(string $section, string $message): RedirectResponse
    {
        return redirect(route('member.profile').'#'.$section)
            ->with('profile_saved', $section)
            ->with('status', $message);
    }

    /**
     * No anchor: browsers ignore autofocus on a URL with one, and focusing the field scrolls to it.
     */
    private function editUrl(string $section): string
    {
        return route('member.profile', ['edit' => $section]);
    }
}
