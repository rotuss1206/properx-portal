<?php

namespace App\Nova\Actions;

use App\Enums\LicenseVerification;
use App\Models\MemberProfile;
use App\Notifications\Member\LicenseNeedsAttention;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * The license verification queue (Agency spec 8.3, 9): the NPN or resident state could not be
 * verified. The reason is mandatory: the person sees it in the member area and the bell, and it is
 * emailed to the agency's principal (agency people) or to the person (individual members).
 * Correcting the NPN or state sends the license back to Pending review (MemberProfile::booted()).
 */
class MarkLicenseNeedsAttention extends Action
{
    public $name = 'Needs attention';

    public $confirmText = 'The person sees this in the member portal. It is emailed to their agency\'s principal, or to them if they are an individual member.';

    public $confirmButtonText = 'Send';

    public function uriKey(): string
    {
        return 'mark-license-needs-attention';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Textarea::make('What to fix', 'reason')
                ->rules('required', 'string', 'max:1000')
                ->help('Say what to correct, e.g. "The NPN does not match the resident state in NIPR." Do not repeat the NPN, names or dates.'),
        ];
    }

    /**
     * @param  Collection<int, MemberProfile>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        // Each reason is written for one person (sole() limits Nova's buttons, this the request).
        if ($models->count() > 1) {
            return Action::danger('Choose one person at a time: the reason is written for them.');
        }

        $reason = trim((string) $fields->get('reason'));
        $changed = 0;

        foreach ($models as $profile) {
            if ($profile->isAgencyEmployee()) {
                continue;
            }

            $profile->forceFill([
                'license_verification' => LicenseVerification::NeedsAttention,
                'license_verification_note' => $reason,
            ])->save();

            LicenseNeedsAttention::send($profile);
            $changed++;
        }

        return Action::message(trans_choice(':count license marked as needing attention; the reason was sent.|:count licenses marked as needing attention; the reason was sent.', $changed));
    }
}
