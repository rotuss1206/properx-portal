<?php

namespace App\Member;

use App\Enums\AgencyStatus;
use App\Enums\LicenseVerification;
use App\Enums\PaymentStatus;
use App\Enums\RateType;
use App\Exceptions\CertificateUnavailableException;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\User;
use App\Services\Certificates\CertificateGeneratorService;
use App\Support\UsStates;
use Carbon\CarbonInterface;

/**
 * The Overview tab of the member dashboard (docs/specs/member-area.md, section 3): membership,
 * license verification, certificate, timeline and the agency line. Read from the member's own
 * profile (and, for agency people, their agency) only.
 */
final class Overview
{
    public function __construct(private readonly User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    public function profile(): ?MemberProfile
    {
        return $this->user->memberProfile;
    }

    public function inAgency(): bool
    {
        return $this->profile()?->agency_id !== null;
    }

    /**
     * Individual members renew their own subscription; agency people the agency's.
     */
    public function paidThrough(): ?CarbonInterface
    {
        $profile = $this->profile();

        return $this->inAgency() ? $profile->agency?->paid_through_at : $profile?->paid_through_at;
    }

    /**
     * @return array{label: string, variant: string, renews: ?CarbonInterface, founding: bool}
     */
    public function membership(): array
    {
        $profile = $this->profile();

        if ($this->inAgency()) {
            $status = $profile->agency?->status ?? AgencyStatus::Pending;
            $variant = match ($status) {
                AgencyStatus::Active => 'success',
                AgencyStatus::Pending, AgencyStatus::PastDue => 'warning',
                AgencyStatus::Inactive => 'danger',
            };

            return [
                'label' => $status->label(),
                'variant' => $variant,
                'renews' => $this->paidThrough(),
                'founding' => $profile->agency?->rate_type === RateType::Founding,
            ];
        }

        // No Stripe data yet counts as active, as for access (MemberProfile::hasRestrictedMembership).
        $status = $profile?->payment_status ?? PaymentStatus::Unknown;
        $restricted = (bool) $profile?->hasRestrictedMembership();

        return [
            'label' => $status === PaymentStatus::Unknown ? PaymentStatus::Active->label() : $status->label(),
            'variant' => match (true) {
                $restricted => 'danger',
                $status === PaymentStatus::PastDue => 'warning',
                default => 'success',
            },
            'renews' => $this->paidThrough(),
            // Hidden for individual members: there is no founding-rate data for them yet;
            // portal.member_area.founding_badge_individuals is the switch for when there is.
            'founding' => false,
        ];
    }

    /**
     * @return array{applicable: bool, label: string, variant: string, npn: ?string, state: ?string}
     */
    public function license(): array
    {
        $profile = $this->profile();

        if ($profile === null || $profile->isAgencyEmployee()) {
            return ['applicable' => false, 'label' => 'Not applicable', 'variant' => 'neutral', 'npn' => null, 'state' => null];
        }

        $verification = $profile->license_verification ?? LicenseVerification::PendingReview;

        return [
            'applicable' => true,
            'label' => $verification->label(),
            'variant' => match ($verification) {
                LicenseVerification::Verified => 'success',
                LicenseVerification::PendingReview => 'warning',
                LicenseVerification::NeedsAttention => 'danger',
            },
            'npn' => $profile->npn,
            'state' => UsStates::ALL[$profile->license_state] ?? $profile->license_state,
        ];
    }

    /**
     * Ready (download through certificates.open, which re-checks access) or the reason it is not.
     *
     * @return array{ready: bool, reason: ?string, validThrough: ?CarbonInterface, url: ?string}
     */
    public function certificate(): array
    {
        $profile = $this->profile();

        if ($profile === null) {
            return ['ready' => false, 'reason' => 'Your member profile is not set up yet.', 'validThrough' => null, 'url' => null];
        }

        try {
            app(CertificateGeneratorService::class)->assertEligible($profile);
        } catch (CertificateUnavailableException $e) {
            return ['ready' => false, 'reason' => $e->getMessage(), 'validThrough' => null, 'url' => null];
        }

        return [
            'ready' => true,
            'reason' => null,
            'validThrough' => $this->paidThrough() ?? $profile->license_expires_at,
            'url' => route('certificates.open', $profile),
        ];
    }

    /** Smallest gap between two timeline points, in percent of the track, so labels never overlap. */
    public const TIMELINE_MIN_GAP = 20;

    /**
     * Joined, start of the current (yearly) period, today and next renewal, placed along the
     * track in proportion to their dates (5 % steps, at least TIMELINE_MIN_GAP apart). Null
     * without a paid period.
     *
     * @return array{points: list<array{key: string, label: string, date: CarbonInterface, pos: int, state: string}>, done: int, today: int, cycle: string}|null
     */
    public function timeline(): ?array
    {
        $next = $this->paidThrough();

        if ($next === null) {
            return null;
        }

        $joined = $this->user->created_at;
        $renewed = $next->copy()->subYear();
        $today = now();

        $points = array_values(array_filter([
            ['key' => 'joined', 'label' => 'Joined', 'date' => $joined, 'state' => 'done'],
            $renewed->greaterThan($joined) && $renewed->lessThan($today)
                ? ['key' => 'renewed', 'label' => 'Renewed', 'date' => $renewed, 'state' => 'done'] : null,
            ['key' => 'today', 'label' => 'Today', 'date' => $today, 'state' => 'today'],
            ['key' => 'next', 'label' => 'Next renewal', 'date' => $next, 'state' => 'next'],
        ]));

        $span = max(1, $joined->diffInSeconds($next));
        $positions = array_map(fn (array $point) => 100 * max(0, $joined->diffInSeconds($point['date'], false)) / $span, $points);
        $positions = self::spread($positions, self::TIMELINE_MIN_GAP);

        foreach ($points as $i => $point) {
            $points[$i]['pos'] = $positions[$i];
        }

        $todayPos = collect($points)->firstWhere('key', 'today')['pos'];
        $renewedPos = collect($points)->firstWhere('key', 'renewed')['pos'] ?? 0;

        return [
            'points' => $points,
            'done' => $renewedPos,
            'today' => $todayPos,
            'cycle' => ($renewed->greaterThan($joined) ? $renewed : $joined)->format('Y').' – '.$next->format('Y'),
        ];
    }

    /**
     * First point at 0, last at 100, the others in order with at least $gap between them,
     * rounded to 5 % steps (the CSS has a class per step).
     *
     * @param  list<float>  $positions
     * @return list<int>
     */
    private static function spread(array $positions, int $gap): array
    {
        $last = count($positions) - 1;
        $positions[0] = 0;
        $positions[$last] = 100;

        for ($i = 1; $i < $last; $i++) {
            $positions[$i] = max($positions[$i], $positions[$i - 1] + $gap);
        }

        for ($i = $last - 1; $i > 0; $i--) {
            $positions[$i] = min($positions[$i], $positions[$i + 1] - $gap);
        }

        return array_map(fn (float $position) => (int) (round($position / 5) * 5), $positions);
    }

    /**
     * @return array{name: string, principal: ?string}|null
     */
    public function agency(): ?array
    {
        $agency = $this->inAgency() ? $this->profile()->agency : null;

        if ($agency === null) {
            return null;
        }

        $principal = $agency->principal;

        return [
            'name' => $agency->legal_name,
            'principal' => $principal?->memberProfile?->fullName() ?? $principal?->name,
        ];
    }

    /**
     * Only individual members upload license documents here (agency people hold NPN and state only).
     */
    public function canUpload(): bool
    {
        $profile = $this->profile();

        return $profile !== null
            && (! $this->inAgency() || config('portal.member_area.agency_people.license_upload'))
            && $this->user->can('create', LicenseDocument::class);
    }
}
