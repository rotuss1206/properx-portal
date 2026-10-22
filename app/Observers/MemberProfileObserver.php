<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;

/**
 * Drops the cached certificate PDF when data printed on it changes and audits
 * profile edits and agency roster changes (Spec 8).
 */
class MemberProfileObserver
{
    private const CERTIFICATE_ATTRIBUTES = [
        'first_name', 'last_name', 'firm_name', 'license_expires_at', 'license_status', 'certificate_code',
    ];

    /**
     * Changed by the system (nightly status refresh, Stripe webhooks, invitations), not by a
     * person editing the profile; payment changes are audited as "payment_status_changed".
     */
    private const SYSTEM_ATTRIBUTES = [
        'license_status', 'updated_at', 'stripe_customer_id', 'payment_status', 'payment_status_updated_at', 'payment_event_at',
        'invite_status',
    ];

    /**
     * Audited as agency roster changes (or as "agency_principal_changed" by the agency).
     */
    private const ROSTER_ATTRIBUTES = ['agency_id', 'member_type', 'agency_role'];

    public function __construct(private CertificateGeneratorService $certificates) {}

    public function created(MemberProfile $profile): void
    {
        if ($profile->agency_id !== null) {
            AuditLog::record('agency_member_added', $profile);
        }
    }

    public function updated(MemberProfile $profile): void
    {
        if ($profile->wasChanged(self::CERTIFICATE_ATTRIBUTES)) {
            $this->certificates->forget($profile);
        }

        if ($profile->wasChanged('agency_id')) {
            if ($profile->getOriginal('agency_id') !== null) {
                AuditLog::record('agency_member_removed', $profile);
            }

            if ($profile->agency_id !== null) {
                AuditLog::record('agency_member_added', $profile);
            }
        }

        // Joining an agency also defaults the person type; that is part of the roster change.
        $roster = $profile->wasChanged('agency_id') ? [...self::ROSTER_ATTRIBUTES, 'person_type'] : self::ROSTER_ATTRIBUTES;

        if (array_diff(array_keys($profile->getChanges()), [...self::SYSTEM_ATTRIBUTES, ...$roster]) !== []) {
            AuditLog::record('profile_updated', $profile);
        }
    }

    public function deleted(MemberProfile $profile): void
    {
        $this->certificates->forget($profile);

        if ($profile->agency_id !== null) {
            AuditLog::record('agency_member_removed', $profile);
        }
    }
}
