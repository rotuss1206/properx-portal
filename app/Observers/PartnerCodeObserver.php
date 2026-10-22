<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\PartnerCode;

/**
 * Audits changes to partner codes (they set agency rates).
 */
class PartnerCodeObserver
{
    public function created(PartnerCode $partnerCode): void
    {
        AuditLog::record('partner_code_created', $partnerCode);
    }

    public function updated(PartnerCode $partnerCode): void
    {
        AuditLog::record('partner_code_updated', $partnerCode);
    }

    public function deleted(PartnerCode $partnerCode): void
    {
        AuditLog::record('partner_code_deleted', $partnerCode);
    }
}
