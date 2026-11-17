<?php

namespace App\Nova\Actions;

use App\Models\MemberProfile;
use App\Services\Memberships\RejoinRule;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Staff lift the 24-month wait after an individual cancellation (RejoinRule), so the person may
 * apply again (properx.org, an agency enrollment, "Move to an agency"). A reason from a fixed list
 * is required and audited as member_rejoin_block_lifted. A later cancellation starts a new wait.
 */
class LiftRejoinWait extends Action
{
    public $name = 'Lift rejoin wait';

    public $confirmText = 'Let this member apply again before 24 months have passed since their cancellation? The reason is recorded in the audit log.';

    public $confirmButtonText = 'Lift the wait';

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make('Reason')
                ->options(RejoinRule::LIFT_REASONS)
                ->rules('required', 'in:'.implode(',', array_keys(RejoinRule::LIFT_REASONS)))
                ->help('Recorded in the audit log.'),
        ];
    }

    /**
     * @param  Collection<int, MemberProfile>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $profile = $models->first();

        if ($profile === null || ! RejoinRule::lift($profile, (string) $fields->get('reason'), request()->user())) {
            return Action::danger('Nothing to lift: this member is not waiting after a cancellation.');
        }

        return Action::message('The wait is lifted. This member may apply again.');
    }

    public function uriKey(): string
    {
        return 'lift-rejoin-wait';
    }
}
