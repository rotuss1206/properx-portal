<?php

namespace App\Http\Controllers;

use App\Enums\LicenseStatus;
use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public certificate check (QR code target). Discloses only name and validity dates;
 * an unknown number gets the same neutral page as an unverifiable one.
 */
class VerifyCertificateController extends Controller
{
    private const CODE_PATTERN = '/^[A-Za-z0-9\-]{1,40}$/';

    /**
     * The short address printed on the certificate: a form for the Certificate ID, which leads to
     * the same result page as the QR code.
     */
    public function form(Request $request): View|RedirectResponse
    {
        $code = preg_replace('/\s+/', '', (string) $request->query('code', ''));

        if ($code !== '' && preg_match(self::CODE_PATTERN, $code)) {
            return redirect()->route('certificates.verify', $code);
        }

        return view('certificates.verify-form', [
            'code' => mb_substr($code, 0, 40),
            'error' => $code === '' ? null : 'Enter the Certificate ID as printed: letters, numbers and dashes only.',
        ]);
    }

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
