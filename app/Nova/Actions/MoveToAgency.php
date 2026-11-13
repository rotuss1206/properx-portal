<?php

namespace App\Nova\Actions;

use App\Enums\AgencyStatus;
use App\Enums\PersonType;
use App\Models\Agency;
use App\Models\MemberProfile;
use App\Services\Agencies\AgencyRosterChanges;
use App\Services\Agencies\CheckoutException;
use App\Services\Agencies\RosterChangeException;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\Hidden;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Staff move an individual member into an agency (client's answer: staff only, Membership
 * Coordinator and Executive Director): the agency pays as for one person added
 * (AgencyRosterChanges::moveMember), the person keeps their account. The cost is shown once an
 * agency is chosen (Stripe's preview at a proration time fixed to the minute, so the amount
 * confirmed is the amount charged). The member's individual subscription is not canceled here.
 */
class MoveToAgency extends Action
{
    public $name = 'Move to an agency';

    public $confirmButtonText = 'Move and charge the agency';

    public $confirmText = 'Moves this individual member into the agency you choose. The agency is charged now, prorated, with its saved payment method.';

    public function fields(NovaRequest $request): array
    {
        $agencies = Agency::query()
            ->where('status', AgencyStatus::Active->value)
            ->whereNotNull('stripe_subscription_id')
            ->get()
            ->sortBy(fn (Agency $agency) => mb_strtolower((string) $agency->legal_name))
            ->mapWithKeys(fn (Agency $agency) => [$agency->getKey() => $agency->legal_name.' (#'.$agency->getKey().', '.$agency->headcount.' people)'])
            ->all();

        return [
            Select::make('Agency')
                ->options($agencies)
                ->rules('required', 'in:'.implode(',', array_keys($agencies)))
                ->help('Active agencies with a Stripe subscription.'),

            Select::make('Person type', 'person_type')
                ->options(collect(PersonType::cases())->mapWithKeys(fn (PersonType $type) => [$type->value => $type->label()])->all())
                ->rules('required', 'in:'.implode(',', array_column(PersonType::cases(), 'value')))
                ->help('A licensed producer needs an NPN of 7 to 10 digits and a resident state on the profile.'),

            Select::make('Reason')
                ->options(AgencyRosterChanges::MOVE_REASONS)
                ->rules('required', 'in:'.implode(',', array_keys(AgencyRosterChanges::MOVE_REASONS)))
                ->help('Recorded in the audit log.'),

            Textarea::make('Cost for the agency', 'cost')
                ->rows(4)
                ->alwaysShow()
                ->readonly()
                ->default('Choose an agency to see the amount.')
                ->dependsOn(['agency'], function (Textarea $field, NovaRequest $request, FormData $formData) {
                    $field->setValue(self::costText(self::quoteFor((int) $formData->get('agency'))));
                }),

            // What was shown, so exactly that is charged (a different amount is shown again instead).
            Hidden::make('Quote', 'quote')
                ->dependsOn(['agency'], function (Hidden $field, NovaRequest $request, FormData $formData) {
                    $quote = self::quoteFor((int) $formData->get('agency'));
                    $field->setValue(isset($quote['total']) ? $quote['total'].'|'.$quote['date'] : '');
                }),

            Heading::make('<p class="text-red-500"><strong>The member’s individual subscription is not canceled.</strong> If they pay for an individual membership on properx.org, cancel it there (WordPress / Stripe) after this move. Nothing is refunded or credited. The audit log notes it.</p>')
                ->asHtml(),
        ];
    }

    /**
     * Stripe's preview for one more person, at a proration time fixed to the minute and cached
     * for it, so the shown amount and the charge agree.
     *
     * @return array{total?: int, date?: int, text?: string, error?: string}
     */
    private static function quoteFor(int $agencyId): array
    {
        $agency = $agencyId > 0 ? Agency::query()->find($agencyId) : null;

        if ($agency === null) {
            return [];
        }

        $date = now()->startOfMinute()->getTimestamp();

        return Cache::remember("nova-move-quote:{$agencyId}:{$date}", 120, function () use ($agency, $date) {
            try {
                $quote = app(AgencyRosterChanges::class)->quote($agency, 1, $date);
            } catch (RosterChangeException $e) {
                return ['error' => $e->getMessage()];
            }

            $text = $quote->noCharge
                ? 'Nothing today (the lower rate for everyone would come out below zero; nothing is credited).'
                : Money::exact($quote->total).' today, prorated until '.$quote->periodEnds->format('F j, Y')
                    .($quote->surcharge > 0 ? ' (incl. credit card surcharge '.Money::exact($quote->surcharge).')' : '')
                    .', paid with '.$quote->paymentMethod.'.';
            $text .= ' People: '.$quote->headcountBefore.' → '.$quote->headcountAfter.'.'
                .($quote->tierChanges() ? ' New tier for everyone: '.$quote->tierAfter->label().', '.Money::format($quote->rate).' per person a year.' : '');

            return ['total' => $quote->total, 'date' => $date, 'text' => $text];
        });
    }

    /**
     * @param  array{total?: int, date?: int, text?: string, error?: string}  $quote
     */
    private static function costText(array $quote): string
    {
        return $quote['text'] ?? $quote['error'] ?? 'Choose an agency to see the amount.';
    }

    /**
     * @param  Collection<int, MemberProfile>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $profile = $models->first();
        $agency = Agency::query()->find((int) $fields->get('agency'));
        $type = PersonType::tryFrom((string) $fields->get('person_type'));
        [$total, $date] = array_pad(explode('|', (string) $fields->get('quote')), 2, null);

        if ($profile === null || $agency === null || $type === null) {
            return Action::danger('Choose the agency and the person type.');
        }

        if (! is_numeric($total) || ! is_numeric($date)) {
            return Action::danger('The amount could not be shown. Choose the agency again, then confirm.');
        }

        try {
            app(AgencyRosterChanges::class)->moveMember($agency, $profile, $type, (string) $fields->get('reason'), request()->user(), (int) $total, (int) $date);
        } catch (CheckoutException|RosterChangeException $e) {
            return Action::danger($e->getMessage());
        }

        return Action::message('Moved into '.$agency->legal_name.'. The agency paid '.Money::exact((int) $total).'. Remember to cancel any individual subscription on properx.org.');
    }

    public static function applies(MemberProfile $profile): bool
    {
        return $profile->agency_id === null && $profile->user !== null && $profile->user->isMember();
    }

    public function uriKey(): string
    {
        return 'move-to-agency';
    }
}
