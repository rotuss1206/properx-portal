<?php

namespace App\Observers;

use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;

/**
 * Drops the cached certificate PDF when data printed on it changes.
 */
class MemberProfileObserver
{
    private const CERTIFICATE_ATTRIBUTES = [
        'first_name', 'last_name', 'firm_name', 'license_expires_at', 'license_status', 'certificate_code',
    ];

    public function __construct(private CertificateGeneratorService $certificates) {}

    public function updated(MemberProfile $profile): void
    {
        if ($profile->wasChanged(self::CERTIFICATE_ATTRIBUTES)) {
            $this->certificates->forget($profile);
        }
    }

    public function deleted(MemberProfile $profile): void
    {
        $this->certificates->forget($profile);
    }
}
