<?php

namespace App\Http\Controllers;

use App\Enums\LicenseStatus;
use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Contracts\View\View;

/**
 * Public certificate check (QR code target). Discloses only name and validity dates;
 * an unknown number gets the same neutral page as an unverifiable one.
 */
class VerifyCertificateController extends Controller
{
    public function __invoke(string $code): View
    {
        $profile = MemberProfile::with(['user:id,created_at', 'agency'])
            ->whereBlindIndex('certificate_code', $code) // the column itself is encrypted
            ->first();

        // The date printed on the certificate (CertificateGeneratorService::validThrough). An
        // agency's paid period may not be known yet. Individual members need a license on file to
        // get a certificate at all, and an expired license ends it, as assertEligible() does.
        $validThrough = $profile ? CertificateGeneratorService::validThrough($profile) : null;
        $individual = $profile?->agency_id === null;

        $status = match (true) {
            $profile === null, $individual && $profile->license_expires_at === null => 'invalid',
            $profile->hasRestrictedMembership() => 'inactive',
            $individual && LicenseStatus::fromExpiryDate($profile->license_expires_at) === LicenseStatus::Expired => 'license_expired',
            $validThrough !== null && LicenseStatus::fromExpiryDate($validThrough) === LicenseStatus::Expired => 'expired',
            default => 'valid',
        };

        return view('certificates.verify', [
            'status' => $status,
            'code' => $code,
            'certificate' => $status === 'invalid' ? null : [
                'name' => $profile->fullName(),
                'member_since' => $profile->user->created_at->format('F Y'),
                'valid_through' => $validThrough?->format('M j, Y'),
                'code' => $profile->certificate_code,
            ],
        ]);
    }
}
