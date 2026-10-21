<?php

namespace App\Nova\Metrics;

use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
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
    public $name = 'My License & Membership';

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
                        ? MenuItem::externalLink('Download my certificate', CertificateGeneratorService::downloadUrl($profile))
                        : null,
                ]))),

            $this->membershipRow($profile),
        ];
    }

    /**
     * Membership dues status from Stripe (Spec 7).
     */
    private function membershipRow(MemberProfile $profile): MetricTableRow
    {
        $paymentUrl = config('portal.payments.payment_url');

        [$icon, $class, $title, $subtitle] = match (true) {
            $profile->hasRestrictedMembership() => [
                'x-circle', 'text-red-500', 'Membership: Inactive',
                'Certificate, library and license documents are unavailable until dues are paid.',
            ],
            $profile->isInPaymentGracePeriod() => [
                'exclamation-circle', 'text-amber-500', 'Membership: Payment past due',
                'Full access until '.$profile->paymentGraceEndsAt()->toFormattedDateString().'. Please update your payment.',
            ],
            $profile->payment_status === PaymentStatus::Active => [
                'check-circle', 'text-green-500', 'Membership: Active', 'Dues are paid.',
            ],
            default => ['information-circle', 'text-gray-400', 'Membership: Status pending', 'No payment information received yet.'],
        };

        return MetricTableRow::make()
            ->icon($icon)
            ->iconClass($class)
            ->title($title)
            ->subtitle($subtitle)
            ->actions(fn () => $paymentUrl && $profile->payment_status !== PaymentStatus::Active
                ? [MenuItem::externalLink('Pay membership dues', $paymentUrl)->openInNewTab()]
                : []);
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
