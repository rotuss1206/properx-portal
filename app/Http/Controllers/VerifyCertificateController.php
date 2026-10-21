<?php

namespace App\Http\Controllers;

use App\Enums\LicenseStatus;
use App\Models\MemberProfile;
use Illuminate\Contracts\View\View;

/**
 * Public certificate check (QR code target). Discloses only name and validity dates;
 * an unknown number gets the same neutral page as an unverifiable one.
 */
class VerifyCertificateController extends Controller
{
    public function __invoke(string $code): View
    {
        $profile = MemberProfile::with('user:id,created_at')
            ->whereBlindIndex('certificate_code', $code) // the column itself is encrypted
            ->first();

        $status = match (true) {
            $profile === null, $profile->license_expires_at === null => 'invalid',
            $profile->hasRestrictedMembership() => 'inactive',
            LicenseStatus::fromExpiryDate($profile->license_expires_at) === LicenseStatus::Expired => 'expired',
            default => 'valid',
        };

        return view('certificates.verify', [
            'status' => $status,
            'code' => $code,
            'certificate' => $status === 'invalid' ? null : [
                'name' => $profile->fullName(),
                'valid_from' => $profile->user->created_at->toFormattedDateString(),
                'valid_through' => $profile->license_expires_at->toFormattedDateString(),
                'code' => $profile->certificate_code,
            ],
        ]);
    }
}
