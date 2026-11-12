<?php

namespace App\Nova\Actions;

use App\Enums\MemberStatus;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;

/**
 * Staff set one person of an agency inactive, or active again (Agency spec 8.4, I1). An inactive
 * person keeps their account and profile but loses what a restricted membership closes
 * (MemberProfile::hasRestrictedMembership: certificate, library, license documents); the agency's
 * invoice does not change before renewal (no credits). The principal is never deactivated: staff
 * reassign the principal first. Audited as agency_member_deactivated / agency_member_reactivated.
 */
abstract class SetAgencyPersonStatus extends Action
{
    abstract protected function status(): MemberStatus;

    /**
     * @param  Collection<int, MemberProfile>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $changed = 0;

        foreach ($models as $profile) {
            if (! static::applies($profile, $this->status())) {
                continue;
            }

            // Quietly: audited once here, not again as a profile edit. The reason is a key from a
            // fixed list, so nothing personal reaches the audit log.
            $profile->forceFill(['status' => $this->status()])->saveQuietly();
            AuditLog::record(
                $this->status() === MemberStatus::Inactive ? 'agency_member_deactivated' : 'agency_member_reactivated',
                $profile,
                details: array_filter(['agency_id' => $profile->agency_id, 'reason' => $fields->get('reason')], fn ($value) => $value !== null),
            );
            $changed++;
        }

        if ($changed === 0) {
            return Action::danger($this->status() === MemberStatus::Inactive
                ? 'Nobody to deactivate: only agency people other than the principal who are active.'
                : 'Nobody to reactivate: only inactive agency people.');
        }

        return Action::message($changed === 1 ? '1 person updated.' : "{$changed} people updated.");
    }

    public static function applies(MemberProfile $profile, MemberStatus $to): bool
    {
        return $profile->agency_id !== null
            && ! $profile->isPrincipalOfAgency()
            && ($profile->status ?? MemberStatus::Active) !== $to;
    }
}
