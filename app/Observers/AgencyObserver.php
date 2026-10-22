<?php

namespace App\Observers;

use App\Enums\MemberType;
use App\Enums\Role;
use App\Models\Agency;
use App\Models\AuditLog;

/**
 * Audits every change to an agency and keeps the principal's role and roster entry
 * in line with principal_user_id.
 */
class AgencyObserver
{
    /**
     * Maintained by the system rather than edited by a person.
     */
    private const SYSTEM_ATTRIBUTES = ['headcount', 'updated_at'];

    public function created(Agency $agency): void
    {
        AuditLog::record('agency_created', $agency);

        $agency->syncPrincipal();
    }

    public function updated(Agency $agency): void
    {
        if ($agency->wasChanged('principal_user_id')) {
            AuditLog::record('agency_principal_changed', $agency);

            $agency->syncPrincipal($agency->getOriginal('principal_user_id'));
        }

        $edited = array_diff(array_keys($agency->getChanges()), [...self::SYSTEM_ATTRIBUTES, 'principal_user_id']);

        if ($edited !== []) {
            AuditLog::record('agency_updated', $agency);
        }
    }

    /**
     * People stay in the portal as individual members; the principal loses the Agency Principal role.
     */
    public function deleting(Agency $agency): void
    {
        $agency->members()->update([
            'agency_id' => null,
            'member_type' => MemberType::Individual->value,
            'agency_role' => null,
        ]);

        if ($agency->principal?->hasRole(Role::AgencyPrincipal->value)) {
            $agency->principal->removeRole(Role::AgencyPrincipal->value);
        }
    }

    public function deleted(Agency $agency): void
    {
        AuditLog::record('agency_deleted', $agency);
    }
}
