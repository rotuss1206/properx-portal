<?php

namespace App\Nova\Actions;

use App\Enums\InviteStatus;
use App\Models\MemberProfile;
use App\Services\Agencies\AgencyInvitations;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Staff's "Resend invitation" (Agency spec 7) for agency people who have not set up their
 * account: every earlier link stops working and a new 14-day invitation is emailed. People
 * whose account is active, or who are on no agency, are left alone.
 */
class ResendInvitation extends Action
{
    public $name = 'Resend invitation';

    public $confirmText = 'Send a new invitation link? Earlier links stop working.';

    public $confirmButtonText = 'Send';

    /**
     * @param  Collection<int, MemberProfile>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $invitations = app(AgencyInvitations::class);
        $sent = 0;

        foreach ($models as $profile) {
            if (! self::applies($profile)) {
                continue;
            }

            $invitations->resend($profile);
            $sent++;
        }

        return $sent === 0
            ? Action::danger('Nobody to invite: the selected people have set up their account or are on no agency.')
            : Action::message($sent === 1 ? 'Invitation sent.' : "{$sent} invitations sent.");
    }

    public static function applies(MemberProfile $profile): bool
    {
        return $profile->agency_id !== null && $profile->invite_status !== InviteStatus::AccountActive;
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
