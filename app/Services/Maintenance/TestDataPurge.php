<?php

namespace App\Services\Maintenance;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\AgencyInvitation;
use App\Models\AgencyRegistrationDraft;
use App\Models\AuditLog;
use App\Models\CertificateIssue;
use App\Models\IntakeSubmission;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\NotificationSetting;
use App\Models\PendingLicenseFile;
use App\Models\ReminderLog;
use App\Models\StripeEvent;
use App\Models\TaxDocument;
use App\Models\User;
use App\Services\Certificates\CertificateGeneratorService;
use App\Services\Documents\DocumentVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Removes test accounts and everything that belongs to them (app:purge-test-data). The selection is
 * only what the caller lists: exact emails, and domains for member accounts (staff accounts only by
 * their exact email). Never the API Service account, never the last Executive Director; roles,
 * permissions, reference data, the library and partner codes stay. Nothing is sent to Stripe.
 *
 * plan() only reads; purge() deletes the rows in one transaction, then the stored files.
 */
class TestDataPurge
{
    /**
     * @param  list<string>  $emails
     * @param  list<string>  $domains
     */
    public function plan(array $emails, array $domains): TestDataPurgePlan
    {
        $emails = array_values(array_unique(array_map(fn (string $email) => Str::lower(trim($email)), $emails)));
        $domains = array_values(array_unique(array_map(fn (string $domain) => Str::lower(ltrim(trim($domain), '@')), $domains)));

        $byEmail = $emails === [] ? collect() : User::query()->whereIn(DB::raw('lower(email)'), $emails)->with('roles')->get();
        $byDomain = $domains === [] ? collect() : User::query()
            ->where(function ($query) use ($domains) {
                foreach ($domains as $domain) {
                    $query->orWhere(DB::raw('lower(email)'), 'like', '%@'.str_replace(['%', '_'], ['\%', '\_'], $domain));
                }
            })
            ->with('roles')
            ->get();

        $apiService = fn (User $user) => $user->hasRole(Role::ApiService->value);

        // A domain never selects staff: they are removed only by their exact email.
        $skippedStaff = $byDomain->filter(fn (User $user) => $user->isStaff() && ! $byEmail->contains('id', $user->id))->values();
        $users = $byEmail->merge($byDomain->reject(fn (User $user) => $user->isStaff()))
            ->unique('id')
            ->reject($apiService)
            ->sortBy('email')
            ->values();

        $userIds = $users->pluck('id')->all();
        $profiles = MemberProfile::query()->whereIn('user_id', $userIds)->get();
        $profileIds = $profiles->pluck('id')->all();

        // An agency goes when its principal is selected (or it has none left) and nobody else on it stays.
        $agencies = Agency::query()
            ->whereIn('principal_user_id', $userIds)
            ->orWhere(fn ($query) => $query->whereNull('principal_user_id')->whereIn('id', $profiles->pluck('agency_id')->filter()->all()))
            ->get();
        [$removedAgencies, $keptAgencies] = $agencies->partition(fn (Agency $agency) => ! MemberProfile::query()
            ->where('agency_id', $agency->id)
            ->whereNotIn('user_id', $userIds)
            ->exists());
        $agencyIds = $removedAgencies->pluck('id')->all();

        $drafts = AgencyRegistrationDraft::query()->get()->filter(function (AgencyRegistrationDraft $draft) use ($emails, $domains) {
            $email = Str::lower(trim((string) data_get($draft->data, 'principal.email')));

            return $email !== '' && (in_array($email, $emails, true) || in_array(Str::after($email, '@'), $domains, true));
        });
        $draftIds = $drafts->pluck('id')->all();

        $customers = $profiles->pluck('stripe_customer_id')->merge($removedAgencies->pluck('stripe_customer_id'))->filter()->values()->all();

        $remainingDirectors = User::role(Role::ExecutiveDirector->value)->whereNotIn('id', $userIds)->count();
        $selectedDirectors = $users->filter(fn (User $user) => $user->hasRole(Role::ExecutiveDirector->value))->count();

        $found = $byEmail->pluck('email')->map(fn ($email) => Str::lower($email))->all();

        return new TestDataPurgePlan(
            users: $users,
            notFound: array_values(array_diff($emails, $found)),
            skippedStaff: $skippedStaff,
            keptAgencies: $keptAgencies->values(),
            problem: $selectedDirectors > 0 && $remainingDirectors === 0
                ? 'The selection holds every Executive Director. Leave one out (app:assign-executive-director first).'
                : null,
            ids: [
                'users' => $userIds,
                'profiles' => $profileIds,
                'agencies' => $agencyIds,
                'drafts' => $draftIds,
                'customers' => $customers,
            ],
            counts: [
                'Accounts' => count($userIds),
                'Staff accounts' => $users->filter(fn (User $user) => $user->isStaff())->count(),
                'Member profiles' => count($profileIds),
                'Agencies' => count($agencyIds),
                'Agency enrollment drafts' => count($draftIds),
                'Agency invitations' => AgencyInvitation::query()->whereIn('member_profile_id', $profileIds)->count(),
                'License documents (with files)' => LicenseDocument::query()->whereIn('user_id', $userIds)->count(),
                'Tax documents (with files)' => TaxDocument::query()->whereIn('user_id', $userIds)->count(),
                'Pending license copies (with files)' => $this->pendingFiles($agencyIds, $draftIds)->count(),
                'Intake submissions' => IntakeSubmission::query()->whereIn('member_profile_id', $profileIds)->count(),
                'Stripe event records (local only)' => $this->stripeEvents($profileIds, $customers)->count(),
                'Certificate issues' => CertificateIssue::query()->whereIn('member_profile_id', $profileIds)->count(),
                'Notifications' => $this->notifications($userIds)->count(),
                'Reminder log entries' => ReminderLog::query()->whereIn('user_id', $userIds)->count(),
                'Notification settings' => NotificationSetting::query()->whereIn('user_id', $userIds)->count(),
                'Sessions' => DB::table('sessions')->whereIn('user_id', $userIds)->count(),
            ],
        );
    }

    /**
     * Deletes what the plan selected and writes one audit record with counts (no emails). Returns
     * how many stored files could not be deleted.
     *
     * @param  array<string, mixed>  $selectors  how the selection was made, without emails
     */
    public function purge(TestDataPurgePlan $plan, array $selectors): int
    {
        $counts = collect($plan->counts)->mapWithKeys(fn (int $count, string $label) => [Str::snake(Str::before($label, ' (')) => $count])->all();
        ['users' => $userIds, 'profiles' => $profileIds, 'agencies' => $agencyIds, 'drafts' => $draftIds, 'customers' => $customers] = $plan->ids;

        $files = LicenseDocument::query()->whereIn('user_id', $userIds)->pluck('stored_filename')
            ->merge(TaxDocument::query()->whereIn('user_id', $userIds)->pluck('stored_filename'))
            ->merge($this->pendingFiles($agencyIds, $draftIds)->pluck('stored_filename'))
            ->filter()
            ->values();
        $emails = $plan->users->pluck('email')->all();

        DB::transaction(function () use ($userIds, $profileIds, $agencyIds, $draftIds, $customers, $emails, $selectors, $counts) {
            $this->notifications($userIds)->delete();
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            DB::table('passkeys')->whereIn('user_id', $userIds)->delete();
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            DB::table(config('permission.table_names.model_has_roles'))->where('model_type', (new User)->getMorphClass())->whereIn(config('permission.column_names.model_morph_key'), $userIds)->delete();
            DB::table(config('permission.table_names.model_has_permissions'))->where('model_type', (new User)->getMorphClass())->whereIn(config('permission.column_names.model_morph_key'), $userIds)->delete();

            IntakeSubmission::query()->whereIn('member_profile_id', $profileIds)->delete();
            $this->stripeEvents($profileIds, $customers)->delete();
            $this->pendingFiles($agencyIds, $draftIds)->delete();
            AgencyRegistrationDraft::query()->whereKey($draftIds)->delete();
            LicenseDocument::query()->whereIn('user_id', $userIds)->delete();
            TaxDocument::query()->whereIn('user_id', $userIds)->delete();

            // Query deletes: no observers, so no per-record audits, staff alerts or Stripe calls.
            Agency::query()->whereKey($agencyIds)->update(['principal_user_id' => null]);
            MemberProfile::query()->whereIn('agency_id', $agencyIds)->update(['agency_id' => null]);
            Agency::query()->whereKey($agencyIds)->delete();
            // Profiles, invitations, certificate issues, reminder logs, notification settings and
            // API keys go with the users (cascading keys).
            User::query()->whereKey($userIds)->delete();

            AuditLog::record('test_data_purged', details: $selectors + ['counts' => $counts]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $failed = 0;
        $vault = app(DocumentVault::class);

        foreach ($files as $path) {
            try {
                $vault->delete($path);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('A purged document file could not be deleted', ['error' => $e::class]);
            }
        }

        $certificates = app(CertificateGeneratorService::class);

        foreach ($profileIds as $profileId) {
            try {
                $certificates->forget((new MemberProfile)->forceFill(['id' => $profileId]));
            } catch (Throwable $e) {
                $failed++;
                Log::warning('A purged certificate cache could not be deleted', ['error' => $e::class]);
            }
        }

        return $failed;
    }

    private function pendingFiles(array $agencyIds, array $draftIds)
    {
        return PendingLicenseFile::query()->where(function ($query) use ($agencyIds, $draftIds) {
            $query->where(fn ($q) => $q->where('owner_type', PendingLicenseFile::OWNER_AGENCY)->whereIn('owner_id', $agencyIds))
                ->orWhere(fn ($q) => $q->where('owner_type', PendingLicenseFile::OWNER_DRAFT)->whereIn('owner_id', $draftIds));
        });
    }

    private function stripeEvents(array $profileIds, array $customers)
    {
        return StripeEvent::query()->where(fn ($query) => $query->whereIn('member_profile_id', $profileIds)->orWhereIn('stripe_customer_id', $customers));
    }

    private function notifications(array $userIds)
    {
        return DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->whereIn('notifiable_id', $userIds);
    }
}
