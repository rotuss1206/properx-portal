<?php

namespace App\Nova;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\RateType;
use App\Enums\Role;
use App\Nova\Actions\ChangeAgencyRateType;
use App\Nova\Actions\ChangeAgencyTier;
use App\Rules\EligiblePrincipal;
use App\Rules\UniqueBlindIndex;
use App\Services\Agencies\AgencyPricingService;
use App\Services\Agencies\AgencyQuote;
use App\Services\Agencies\HeadcountTooLowException;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Fields\URL;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Laravel\Nova\Panel;

/**
 * Staff tool for agencies (Executive Director, Membership Coordinator). Principals manage
 * their own agency outside Nova, so this resource is closed to them even where
 * AgencyPolicy would allow it.
 *
 * @extends \App\Nova\Resource<\App\Models\Agency>
 */
class Agency extends Resource
{
    public static $model = \App\Models\Agency::class;

    public static $title = 'legal_name';

    public static $search = ['legal_name', 'dba'];

    public static $group = 'Agencies';

    public static $with = ['principal', 'partnerCode'];

    public static function label()
    {
        return 'Agencies';
    }

    /**
     * Names match partially; the encrypted agency NPN and FEIN match exactly through their blind indexes.
     */
    protected static function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search) {
            static::initializeSearch($query, $search, static::$search);

            foreach (['agency_npn', 'fein'] as $attribute) {
                $query->orWhere(fn (Builder $query) => $query->whereBlindIndex($attribute, $search));
            }
        });
    }

    public function authorizedTo(Request $request, string $ability): bool
    {
        return static::viewerIsAgencyStaff($request) && parent::authorizedTo($request, $ability);
    }

    public function authorizeTo(Request $request, string $ability): void
    {
        throw_unless(static::viewerIsAgencyStaff($request), AuthorizationException::class);

        parent::authorizeTo($request, $ability);
    }

    private static function viewerIsAgencyStaff(Request $request): bool
    {
        return (bool) Nova::user($request)?->checkPermissionTo('agencies.view');
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Legal Name')
                ->rules('required', 'max:255')
                ->sortable(),

            Text::make('DBA')
                ->rules('nullable', 'max:255')
                ->hideFromIndex(),

            Select::make('Agency Type')
                ->options(self::enumOptions(AgencyType::cases()))
                ->displayUsingLabels()
                ->rules('required', Rule::enum(AgencyType::class))
                ->hideFromIndex()
                ->filterable(),

            // Read-only (Agency spec 3): the tier follows the headcount, the rate type is locked at
            // payment. The Executive Director overrides them with the Change tier / rate type actions.
            Select::make('Tier')
                ->options(self::enumOptions(AgencyTier::cases()))
                ->displayUsingLabels()
                ->readonly()
                ->hideWhenCreating()
                ->help('Follows the headcount. To override it, use the "Change tier" action.')
                ->sortable()
                ->filterable(),

            Select::make('Rate Type')
                ->options(self::enumOptions(RateType::cases()))
                ->displayUsingLabels()
                ->readonly()
                ->hideWhenCreating()
                ->help('Founding rates are locked at payment and stay while the agency remains a member without a break. To override it, use the "Change rate type" action.')
                ->filterable(),

            Text::make('Rate', fn () => ($quote = $this->quote()) ? Money::format($quote->rate).' / person' : '—')
                ->exceptOnForms(),

            Number::make('Headcount')
                ->exceptOnForms()
                ->sortable(),

            Badge::make('Status')
                ->map([
                    AgencyStatus::Active->value => 'success',
                    AgencyStatus::Pending->value => 'info',
                    AgencyStatus::PastDue->value => 'warning',
                    AgencyStatus::Inactive->value => 'danger',
                ])
                ->labels(self::enumOptions(AgencyStatus::cases()))
                ->withIcons()
                ->sortable()
                ->filterable(),

            Select::make('Status')
                ->options(self::enumOptions(AgencyStatus::cases()))
                ->rules('required', Rule::enum(AgencyStatus::class))
                ->default(AgencyStatus::Pending->value)
                ->onlyOnForms(),

            // Reassigning the principal moves the Agency Principal role (staff only, never self-serve).
            // Licensed producers with an NPN on this agency's roster (a new agency without people:
            // an individual member on no agency). Checked again by Agency::principalProblem() on save.
            BelongsTo::make('Principal', 'principal', Member::class)
                ->searchable()
                ->rules('required', new EligiblePrincipal($this->agencyBeingEdited($request)))
                ->relatableQueryUsing(function (NovaRequest $request, Builder $query) {
                    $agency = $this->agencyBeingEdited($request);
                    $onRoster = $agency->exists && $agency->members()->exists();

                    return $query
                        ->role(Role::Member->value)
                        ->whereHas('memberProfile', fn (Builder $profile) => $profile
                            ->licensed()
                            ->whereNotNull('npn_index')
                            ->when(
                                $onRoster,
                                fn (Builder $profile) => $profile->where('agency_id', $agency->getKey()),
                                fn (Builder $profile) => $profile->whereNull('agency_id'),
                            ));
                })
                ->help("A licensed producer with an NPN from this agency's people (agency employees cannot lead an agency). The previous principal stays on the roster as a member."),

            BelongsTo::make('Partner Code', 'partnerCode', PartnerCode::class)
                ->nullable()
                ->hideFromIndex(),

            new Panel('Agency Details', $this->agencyDetailFields($request)),

            new Panel('Billing', $this->billingFields()),

            HasMany::make('People', 'members', MemberProfile::class),
        ];
    }

    /**
     * Encrypted at rest: not sortable or filterable; agency NPN and FEIN are searchable by exact value.
     *
     * @return array<int, Field>
     */
    private function agencyDetailFields(NovaRequest $request): array
    {
        return [
            Text::make('Agency NPN', 'agency_npn')
                ->rules('nullable', 'digits_between:7,10', new UniqueBlindIndex(\App\Models\Agency::class, 'agency_npn', $request->resourceId))
                ->help('Only if the agency holds an entity license.')
                ->hideFromIndex(),

            Text::make('FEIN')
                ->rules('nullable', 'regex:/^\d{2}-?\d{7}$/', new UniqueBlindIndex(\App\Models\Agency::class, 'fein', $request->resourceId))
                ->help('For invoices and receipts, e.g. 12-3456789.')
                ->hideFromIndex(),

            Textarea::make('Business Address', 'legal_address')
                ->rows(2)
                ->rules('required', 'max:500', 'not_regex:/\bP\.?\s*O\.?\s*Box\b/i')
                ->help('No PO boxes.'),

            Boolean::make('Mailing Same As Business Address', 'mailing_same_as_legal')
                ->default(true)
                ->hideFromIndex(),

            Textarea::make('Mailing Address')
                ->rows(2)
                ->hideFromIndex()
                ->dependsOn('mailing_same_as_legal', function (Textarea $field, NovaRequest $request, FormData $formData) {
                    if ($formData->boolean('mailing_same_as_legal')) {
                        $field->hide();
                    } else {
                        $field->rules('required', 'max:500');
                    }
                }),

            Text::make('Main Phone', 'phone')
                ->rules('required', 'max:32')
                ->hideFromIndex(),

            URL::make('Website')
                ->rules('nullable', 'url', 'max:255')
                ->hideFromIndex(),

            Text::make('Billing Email')
                ->rules('required', 'email', 'max:255')
                ->help('Invoices and receipts.')
                ->hideFromIndex(),

            Select::make('How Did You Hear About Us?', 'referral_source')
                ->options(\App\Models\Agency::REFERRAL_SOURCES)
                ->displayUsingLabels()
                ->nullable()
                ->hideFromIndex(),
        ];
    }

    /**
     * Read-only: billing links are written by the enrollment and Stripe integration.
     *
     * @return array<int, Field>
     */
    private function billingFields(): array
    {
        return [
            Text::make('Annual Dues', fn () => $this->quote()?->summary() ?? 'Needs at least 2 people')
                ->onlyOnDetail(),

            Text::make('Partner Rate', fn () => match (true) {
                $this->quote()?->partnerRateApplied => 'Applied',
                $this->quote()?->partnerCodeValid => 'Not needed: the agency rate is lower',
                $this->resource->partner_code_id !== null => 'Code not valid',
                default => '—',
            })->onlyOnDetail(),

            Text::make('Stripe Customer ID', 'stripe_customer_id')->onlyOnDetail(),

            Text::make('Stripe Subscription ID', 'stripe_subscription_id')->onlyOnDetail(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        $canOverride = fn (Request $request) => (bool) Nova::user($request)?->checkPermissionTo('agencies.pricing.override');

        return [
            ChangeAgencyTier::make()->canSee($canOverride)->canRun($canOverride),
            ChangeAgencyRateType::make()->canSee($canOverride)->canRun($canOverride),
        ];
    }

    private function agencyBeingEdited(NovaRequest $request): \App\Models\Agency
    {
        if ($this->resource->exists) {
            return $this->resource;
        }

        return $request->resourceId ? \App\Models\Agency::query()->findOrNew($request->resourceId) : new \App\Models\Agency;
    }

    private function quote(): ?AgencyQuote
    {
        if (! $this->resource->exists || $this->resource->rate_type === null) {
            return null;
        }

        try {
            return app(AgencyPricingService::class)->quoteForAgency($this->resource);
        } catch (HeadcountTooLowException) {
            return null;
        }
    }

    /**
     * @param  list<AgencyType|AgencyTier|RateType|AgencyStatus>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all();
    }
}
