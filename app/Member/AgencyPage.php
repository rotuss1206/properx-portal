<?php

namespace App\Member;

use App\Enums\InviteStatus;
use App\Enums\LicenseVerification;
use App\Enums\MemberStatus;
use App\Enums\PersonType;
use App\Models\Agency;
use App\Models\MemberProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * /member/agency for the agency's principal (Agency spec 8.1, G1-G2): the summary, the people
 * with their invitation, license and account status, and who may get a new invitation. Only the
 * principal by agencies.principal_user_id, with own-agency.manage (AgencyPolicy::update).
 */
final class AgencyPage
{
    public const FILTERS = [
        'all' => 'Everyone',
        'not_accepted' => 'Not set up',
        'needs_attention' => 'Needs attention',
        'pending_review' => 'Pending review',
        'inactive' => 'Inactive',
    ];

    private function __construct(public readonly User $principal, public readonly Agency $agency) {}

    public static function for(User $user): ?self
    {
        $profile = $user->memberProfile;
        $agency = $profile?->agency;

        if ($agency === null || ! $profile->isPrincipalOfAgency() || ! $user->can('update', $agency)) {
            return null;
        }

        return new self($user, $agency);
    }

    public static function visible(User $user): bool
    {
        return self::for($user) !== null;
    }

    /**
     * @return array{tier: ?string, rate: ?int, rate_kind: string, people: int, status: string, variant: string, paid_through: ?CarbonInterface}
     */
    public function summary(): array
    {
        $agency = $this->agency;

        return [
            'tier' => $agency->tier?->label(),
            'rate' => $agency->currentRate(),
            'rate_kind' => $agency->partnerRateApplies() ? 'Partner rate' : ($agency->rate_type?->label() ?? '').' rate',
            'people' => $agency->headcount,
            'status' => $agency->status->label(),
            'variant' => match ($agency->status->value) {
                'active' => 'success',
                'inactive' => 'danger',
                default => 'warning',
            },
            'paid_through' => $agency->paid_through_at,
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, email: ?string, type: string, principal: bool, invite: string, invite_variant: string, license: ?string, license_variant: ?string, reason: ?string, account: string, account_variant: string, can_resend: bool, resend_after: ?CarbonInterface, filters: list<string>}>
     */
    public function people(string $filter = 'all'): Collection
    {
        $people = $this->agency->members()
            ->with(['user:id,email', 'invitations' => fn ($query) => $query->latest('sent_at')->limit(1)])
            ->orderByRaw('user_id = ? desc', [$this->agency->principal_user_id])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (MemberProfile $profile) => $this->row($profile));

        return $filter === 'all' ? $people : $people->filter(fn (array $row) => in_array($filter, $row['filters'], true))->values();
    }

    /**
     * @return array<string, int>
     */
    public function counts(Collection $people): array
    {
        return collect(array_keys(self::FILTERS))
            ->mapWithKeys(fn (string $key) => [$key => $key === 'all' ? $people->count() : $people->filter(fn (array $row) => in_array($key, $row['filters'], true))->count()])
            ->all();
    }

    /**
     * When this person may get another invitation from the principal: never for an account
     * already set up or for the principal themselves; otherwise once per
     * portal.invitations.principal_resend_minutes after the last one.
     */
    public function resendAfter(MemberProfile $profile): ?CarbonInterface
    {
        $last = $profile->relationLoaded('invitations')
            ? $profile->invitations->first()?->sent_at
            : $profile->invitations()->latest('sent_at')->value('sent_at');

        return $last?->copy()->addMinutes((int) config('portal.invitations.principal_resend_minutes'));
    }

    public function mayResend(MemberProfile $profile): bool
    {
        return $profile->agency_id === $this->agency->getKey()
            && $profile->user_id !== $this->principal->getKey()
            && $profile->invite_status !== InviteStatus::AccountActive;
    }

    /**
     * @return array{id: int, name: string, email: ?string, type: string, principal: bool, invite: string, invite_variant: string, license: ?string, license_variant: ?string, reason: ?string, account: string, account_variant: string, can_resend: bool, resend_after: ?CarbonInterface, filters: list<string>}
     */
    private function row(MemberProfile $profile): array
    {
        $verification = $profile->isAgencyEmployee() ? null : ($profile->license_verification ?? LicenseVerification::PendingReview);
        $invite = $profile->invite_status ?? InviteStatus::Pending;
        $resendAfter = $this->mayResend($profile) ? $this->resendAfter($profile) : null;

        return [
            'id' => $profile->getKey(),
            'name' => $profile->fullName(),
            'email' => $profile->user?->email,
            'type' => $profile->person_type === PersonType::AgencyEmployee ? 'Employee' : 'Producer',
            'principal' => $profile->user_id === $this->principal->getKey(),
            'invite' => $invite->label(),
            'invite_variant' => $invite === InviteStatus::AccountActive ? 'success' : 'warning',
            'license' => $verification?->label(),
            'license_variant' => match ($verification) {
                LicenseVerification::Verified => 'success',
                LicenseVerification::NeedsAttention => 'danger',
                LicenseVerification::PendingReview => 'warning',
                null => null,
            },
            'reason' => $verification === LicenseVerification::NeedsAttention ? $profile->license_verification_note : null,
            'account' => ($profile->status ?? MemberStatus::Active)->label(),
            'account_variant' => $profile->status === MemberStatus::Inactive ? 'danger' : 'success',
            'can_resend' => $this->mayResend($profile),
            'resend_after' => $resendAfter !== null && $resendAfter->isFuture() ? $resendAfter : null,
            'filters' => array_values(array_filter([
                $invite !== InviteStatus::AccountActive ? 'not_accepted' : null,
                $verification === LicenseVerification::NeedsAttention ? 'needs_attention' : null,
                $verification === LicenseVerification::PendingReview ? 'pending_review' : null,
                $profile->status === MemberStatus::Inactive ? 'inactive' : null,
            ])),
        ];
    }
}
