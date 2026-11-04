<?php

namespace App\Member;

use App\Enums\LicenseStatus;
use App\Enums\LicenseVerification;
use App\Enums\NotificationCategory;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Support\UsStates;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the profile page shows and allows for one member. Access follows Nova's rules: everyone
 * edits their contact details, firm name and notification preferences, also with a canceled
 * membership; agency people hold NPN and resident state only; agency employees hold no license.
 */
final class ProfilePage
{
    public const SECTIONS = ['contact', 'business', 'license', 'security'];

    public function __construct(private readonly User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    public function profile(): ?MemberProfile
    {
        return $this->user->memberProfile;
    }

    /**
     * The section being edited (?edit=), only one that exists for this person.
     */
    public function editing(?string $section): ?string
    {
        return in_array($section, $this->editableSections(), true) ? $section : null;
    }

    /**
     * @return list<string>
     */
    public function editableSections(): array
    {
        $profile = $this->profile();

        if ($profile === null || ! $this->user->can('update', $profile)) {
            return ['security'];
        }

        return $this->holdsLicense() ? self::SECTIONS : ['contact', 'business', 'security'];
    }

    public function holdsLicense(): bool
    {
        $profile = $this->profile();

        return $profile !== null && ! $profile->isAgencyEmployee();
    }

    public function fullLicense(): bool
    {
        return $this->holdsLicense() && $this->profile()->agency_id === null;
    }

    /**
     * "Licensed insurance agent · Mercer Insurance Group".
     */
    public function caption(): string
    {
        $profile = $this->profile();
        $business = $profile?->agency?->legal_name ?? $profile?->firm_name;

        return collect([$this->holdsLicense() ? 'Licensed insurance agent' : 'Agency staff', $business])->filter()->implode(' · ');
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim((string) $this->profile()?->fullName() ?: $this->user->name)) ?: [])
            ->filter()
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->pipe(fn (Collection $letters) => $letters->count() > 1 ? $letters->first().$letters->last() : (string) $letters->first());
    }

    public function stateName(): ?string
    {
        $state = $this->profile()?->license_state;

        return $state === null ? null : (UsStates::ALL[$state] ?? $state);
    }

    /**
     * @return array{label: string, variant: string}|null
     */
    public function expiryBadge(): ?array
    {
        $profile = $this->profile();

        if ($profile?->license_expires_at === null) {
            return null;
        }

        return match (LicenseStatus::fromExpiryDate($profile->license_expires_at)) {
            LicenseStatus::Active => ['label' => 'Current', 'variant' => 'success'],
            LicenseStatus::Expiring => ['label' => 'Expiring soon', 'variant' => 'warning'],
            LicenseStatus::Expired => ['label' => 'Expired', 'variant' => 'danger'],
        };
    }

    /**
     * Saving would send a verified (or flagged) license back to review only if it is not there yet.
     */
    public function licenseCanReturnToReview(): bool
    {
        return $this->profile()?->license_verification !== LicenseVerification::PendingReview;
    }

    public function passwordChangedAt(): ?CarbonInterface
    {
        return AuditLog::query()
            ->where('action', 'password_changed')
            ->where('resource_type', $this->user->getMorphClass())
            ->where('resource_id', $this->user->getKey())
            ->latest('id')
            ->value('created_at');
    }

    /**
     * Fortify replaces a code when it is used, so the count stays as generated.
     */
    public function recoveryCodeCount(): int
    {
        return $this->user->two_factor_recovery_codes === null ? 0 : count($this->user->recoveryCodes());
    }

    /**
     * Categories are opt-out: no row means enabled.
     *
     * @return list<array{category: NotificationCategory, enabled: bool}>
     */
    public function notificationSettings(): array
    {
        $settings = $this->user->notificationSettings()->get()->keyBy(fn (NotificationSetting $setting) => $setting->category->value);

        return array_map(fn (NotificationCategory $category) => [
            'category' => $category,
            'enabled' => $settings->get($category->value)?->enabled ?? true,
        ], NotificationCategory::cases());
    }
}
