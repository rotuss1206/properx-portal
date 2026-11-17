<?php

namespace App\Console\Commands;

use App\Enums\AgencyStatus;
use App\Enums\LicenseVerification;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\Staff\StaffDailySummary;
use App\Services\StaffAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Nova\Notifications\Notification as NovaNotificationRecord;

/**
 * The staff's morning email (portal.staff_summary.enabled, off by default): each person in a role
 * that the bell serves (App\Services\StaffAlerts) gets what waits for them, as counts, and only when
 * something does. The Membership Coordinator: license documents and licenses to review, and their
 * unread alerts; the Executive Director: past-due agencies and members, and their unread alerts.
 */
#[Signature('app:send-staff-summary')]
#[Description('Email each person on the staff what waits for them today (counts only)')]
class SendStaffSummary extends Command
{
    /** What an unread alert of each kind is called in the summary. */
    private const KIND_LABELS = [
        'license_document_uploaded' => 'new license document(s) uploaded by members',
        'member_intake' => 'new member(s) from properx.org',
        'license_fixed' => 'license(s) fixed after "Needs attention"',
        'invitation_requested' => 'request(s) for a new invitation',
        'rejoin_blocked' => 'application(s) refused under the 24-month rule',
        'payment_failed' => 'failed payment(s)',
        'membership_ended' => 'membership(s) that ended',
        'integration_refused' => 'integration problem(s)',
    ];

    public function handle(): int
    {
        if (! config('portal.staff_summary.enabled')) {
            $this->info('The staff summary is off (STAFF_SUMMARY_ENABLED).');

            return self::SUCCESS;
        }

        $sent = 0;
        $staff = User::role([Role::MembershipCoordinator->value, Role::ExecutiveDirector->value])->get()->reject(fn (User $user) => $user->isMember());

        foreach ($staff as $user) {
            $lines = $this->linesFor($user);

            if ($lines !== []) {
                $user->notify(new StaffDailySummary($lines));
                $sent++;
            }
        }

        $this->info("Staff summaries sent: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function linesFor(User $user): array
    {
        $lines = [];

        if ($user->hasRole(Role::MembershipCoordinator->value)) {
            $lines[] = ['label' => 'license document(s) waiting for review', 'count' => LicenseDocument::query()->where('verification_status', VerificationStatus::Pending->value)->count()];
            $lines[] = ['label' => 'license(s) in the verification queue', 'count' => MemberProfile::query()->where('license_verification', LicenseVerification::PendingReview->value)->count()];
        }

        if ($user->hasRole(Role::ExecutiveDirector->value)) {
            $lines[] = ['label' => 'agency membership(s) past due', 'count' => Agency::query()->where('status', AgencyStatus::PastDue->value)->count()];
            $lines[] = ['label' => 'individual membership(s) past due', 'count' => MemberProfile::query()->whereNull('agency_id')->where('payment_status', PaymentStatus::PastDue->value)->count()];
        }

        $unread = NovaNotificationRecord::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')
            ->get(['data'])
            ->map(fn ($row) => is_array($row->data) ? ($row->data['ppx_kind'] ?? null) : (json_decode((string) $row->data, true)['ppx_kind'] ?? null))
            ->filter(fn ($kind) => in_array($kind, StaffAlerts::kindsFor($user), true))
            ->countBy();

        foreach ($unread as $kind => $count) {
            $lines[] = ['label' => 'unread: '.self::KIND_LABELS[$kind], 'count' => $count];
        }

        return array_values(array_filter($lines, fn (array $line) => $line['count'] > 0));
    }
}
