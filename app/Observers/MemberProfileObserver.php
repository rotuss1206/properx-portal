<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;

/**
 * Drops the cached certificate PDF when data printed on it changes and audits
 * profile edits (Spec §8).
 */
class MemberProfileObserver
{
    private const CERTIFICATE_ATTRIBUTES = [
        'first_name', 'last_name', 'firm_name', 'license_expires_at', 'license_status', 'certificate_code',
    ];

    /**
     * Changed by the system (nightly status refresh), not by a person editing the profile.
     */
    private const SYSTEM_ATTRIBUTES = ['license_status', 'updated_at'];

    public function __construct(private CertificateGeneratorService $certificates) {}

    public function updated(MemberProfile $profile): void
    {
        if ($profile->wasChanged(self::CERTIFICATE_ATTRIBUTES)) {
            $this->certificates->forget($profile);
        }

        if (array_diff(array_keys($profile->getChanges()), self::SYSTEM_ATTRIBUTES) !== []) {
            AuditLog::record('profile_updated', $profile);
        }
    }

    public function deleted(MemberProfile $profile): void
    {
        $this->certificates->forget($profile);
    }
}
