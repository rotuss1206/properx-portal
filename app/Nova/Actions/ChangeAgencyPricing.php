<?php

namespace App\Nova\Actions;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Models\Agency;
use App\Models\AuditLog;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Manual override of a value that normally follows from the rules (Agency spec 3): the tier
 * from the headcount, the rate type from the payment date. Executive Director only
 * (agencies.pricing.override, checked by the Agency resource), with a mandatory reason
 * picked from a fixed list (no free text, so nothing personal reaches the audit log).
 * Saved quietly and audited as its own action with from, to and the reason key.
 */
abstract class ChangeAgencyPricing extends Action
{
    public const REASONS = [
        'data_entry_correction' => 'Data entry correction',
        'approved_pricing_exception' => 'Approved pricing exception',
        'renewal_adjustment' => 'Renewal adjustment',
        'client_request' => 'Client request',
        'other' => 'Other',
    ];

    /** Agency attribute, e.g. "tier". */
    abstract protected function attribute(): string;

    abstract protected function auditAction(): string;

    /**
     * @return list<AgencyTier|RateType>
     */
    abstract protected function cases(): array;

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make($this->name, 'value')
                ->options(collect($this->cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all())
                ->rules('required', 'in:'.implode(',', array_map(fn ($case) => $case->value, $this->cases()))),

            Select::make('Reason')
                ->options(self::REASONS)
                ->rules('required', 'in:'.implode(',', array_keys(self::REASONS)))
                ->help('Recorded in the audit log.'),
        ];
    }

    /**
     * @param  Collection<int, Agency>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $attribute = $this->attribute();
        $changed = 0;

        foreach ($models as $agency) {
            $from = $agency->{$attribute}?->value;

            if ($from === $fields->get('value')) {
                continue;
            }

            $agency->forceFill([$attribute => $fields->get('value')])->saveQuietly();

            AuditLog::record($this->auditAction(), $agency, details: [
                'from' => $from,
                'to' => $fields->get('value'),
                'reason' => (string) $fields->get('reason'),
            ]);

            $changed++;
        }

        return Action::message(trans_choice(':count agency updated.|:count agencies updated.', $changed));
    }
}
