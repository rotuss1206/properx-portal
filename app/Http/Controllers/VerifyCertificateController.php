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

        // Individual members: through the license expiry; agency people: through the agency's paid
        // period (CertificateGeneratorService::validThrough), which may not be known yet.
        $validThrough = $profile ? CertificateGeneratorService::validThrough($profile) : null;

        $status = match (true) {
            $profile === null, $profile->agency_id === null && $validThrough === null => 'invalid',
            $profile->hasRestrictedMembership() => 'inactive',
            $validThrough !== null && LicenseStatus::fromExpiryDate($validThrough) === LicenseStatus::Expired => 'expired',
            default => 'valid',
        };

        return view('certificates.verify', [
            'status' => $status,
            'code' => $code,
            'certificate' => $status === 'invalid' ? null : [
                'name' => $profile->fullName(),
                'valid_from' => $profile->user->created_at->toFormattedDateString(),
                'valid_through' => $validThrough?->toFormattedDateString(),
                'code' => $profile->certificate_code,
            ],
        ]);
    }
}
