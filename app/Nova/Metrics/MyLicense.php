<?php

namespace App\Nova\Metrics;

use App\Enums\LicenseStatus;
use App\Exceptions\CertificateUnavailableException;
use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Metrics\MetricTableRow;
use Laravel\Nova\Metrics\Table;

/**
 * The signed-in member's own license status and expiry date.
 */
class MyLicense extends Table
{
    public $name = 'My License';

    public $emptyText = 'No member profile yet. Please contact the membership team.';

    /**
     * @return array<int, MetricTableRow>
     */
    public function calculate(NovaRequest $request): array
    {
        $profile = $request->user()->memberProfile;

        if ($profile === null) {
            return [];
        }

        [$icon, $class] = match ($profile->license_status) {
            LicenseStatus::Active => ['check-circle', 'text-green-500'],
            LicenseStatus::Expiring => ['exclamation-circle', 'text-amber-500'],
            default => ['x-circle', 'text-red-500'],
        };

        $expires = $profile->license_expires_at;

        return [
            MetricTableRow::make()
                ->icon($icon)
                ->iconClass($class)
                ->title('Status: '.ucfirst($profile->license_status->value))
                ->subtitle($expires
                    ? 'Expires '.$expires->toFormattedDateString().' ('.$expires->diffForHumans().')'
                    : 'No expiry date on file')
                ->actions(fn () => array_values(array_filter([
                    MenuItem::link('Open my profile', "/resources/member-profiles/{$profile->getKey()}"),
                    $this->canIssueCertificate($profile)
                        ? MenuItem::externalLink('Download my certificate', route('certificates.download', $profile))
                        : null,
                ]))),
        ];
    }

    public function uriKey(): string
    {
        return 'my-license';
    }

    private function canIssueCertificate(MemberProfile $profile): bool
    {
        try {
            app(CertificateGeneratorService::class)->assertEligible($profile);

            return true;
        } catch (CertificateUnavailableException) {
            return false;
        }
    }
}
