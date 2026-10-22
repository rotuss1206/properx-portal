<?php

namespace App\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

/**
 * MGA/FMO partner codes. With a valid code an agency pays the lower of $149 and its tier rate.
 *
 * @extends \App\Nova\Resource<\App\Models\PartnerCode>
 */
class PartnerCode extends Resource
{
    public static $model = \App\Models\PartnerCode::class;

    public static $title = 'code';

    public static $search = ['code', 'partner_name'];

    public static $group = 'Agencies';

    public static function label()
    {
        return 'Partner Codes';
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->withCount('agencies');
    }

    /**
     * Same staff-only rule as the Agency resource.
     */
    public function authorizedTo(Request $request, string $ability): bool
    {
        return (bool) Nova::user($request)?->checkPermissionTo('agencies.view') && parent::authorizedTo($request, $ability);
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Code')
                ->rules('required', 'max:50', 'alpha_dash')
                ->creationRules('unique:partner_codes,code')
                ->updateRules('unique:partner_codes,code,{{resourceId}}')
                ->help('Stored in upper case; partner QR links prefill it.')
                ->sortable(),

            Text::make('Partner Name')
                ->rules('required', 'max:255')
                ->sortable(),

            Boolean::make('Active')
                ->default(true)
                ->filterable(),

            DateTime::make('Expires At')
                ->nullable()
                ->rules('nullable', 'date')
                ->sortable()
                ->filterable(),

            Badge::make('Valid Now', fn () => $this->resource->isValid() ? 'yes' : 'no')
                ->map(['yes' => 'success', 'no' => 'danger'])
                ->labels(['yes' => 'Valid', 'no' => 'Not valid'])
                ->exceptOnForms(),

            Number::make('Agencies', 'agencies_count')->onlyOnIndex()->sortable(),

            HasMany::make('Agencies', 'agencies', Agency::class),
        ];
    }
}
