<?php

namespace App\Nova;

use App\Enums\AgencyRole;
use App\Enums\LicenseStatus;
use App\Enums\MemberStatus;
use App\Enums\MemberType;
use App\Enums\NotificationCategory;
use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Exceptions\CertificateUnavailableException;
use App\Models\NotificationSetting;
use App\Nova\Actions\DownloadMyCertificate;
use App\Nova\Actions\GenerateCertificate;
use App\Services\Certificates\CertificateGeneratorService;
use App\Support\UsStates;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

/**
 * @extends \App\Nova\Resource<\App\Models\MemberProfile>
 */
class MemberProfile extends Resource
{
    public static $model = \App\Models\MemberProfile::class;

    /** Badge value for people without a license status (agency employees). */
    private const NO_LICENSE = 'none';

    public static $search = ['first_name', 'last_name', 'license_number'];

    /**
     * Names and license number match partially; the encrypted NPN, Member ID and
     * certificate number match exactly through their blind indexes.
     */
    protected static function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search) {
            static::initializeSearch($query, $search, static::$search);

            foreach (['npn', 'member_number', 'certificate_code'] as $attribute) {
                $query->orWhere(fn (Builder $query) => $query->whereBlindIndex($attribute, $search));
            }
        });
    }

    public static $group = 'Members';

    public static function label()
    {
        return static::viewerIsMember(request()) ? 'My Profile' : 'Member Profiles';
    }

    public static function singularLabel()
    {
        return static::viewerIsMember(request()) ? 'My Profile' : 'Member Profile';
    }

    public static function group()
    {
        return static::viewerIsMember(request()) ? 'My Account' : static::$group;
    }

    public function title()
    {
        return $this->resource->fullName();
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        $query = static::ownRecordsUnless($request, $query, 'members.view');

        if (! static::isAgencyRoster($request)) {
            return $query;
        }

        // Unless a column is sorted: the principal first ("principal" sorts after "member"),
        // then by name. Nova applies its default "id desc" before this, hence reorder().
        return $query->with('user')->when(blank($request->get('orderBy')), fn (Builder $query) => $query
            ->reorder()
            ->orderByDesc('agency_role')
            ->orderBy('last_name')
            ->orderBy('first_name'));
    }

    /**
     * The People list on an agency (Agency spec 7).
     */
    private static function isAgencyRoster(NovaRequest $request): bool
    {
        return $request->viaResource === Agency::uriKey() && $request->viaRelationship === 'members' && $request->isResourceIndexRequest();
    }

    public static function relatableQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::ownRecordsUnless($request, $query, 'members.view');
    }

    public function fields(NovaRequest $request): array
    {
        if (static::isAgencyRoster($request)) {
            return $this->rosterFields();
        }

        return [
            ID::make()->sortable(),

            BelongsTo::make('Account', 'user', Member::class)
                ->searchable()
                ->canSee(fn (Request $request) => static::viewerIsStaff($request))
                ->readonly(fn () => $this->resource->exists),

            Text::make('Name', fn () => $this->resource->fullName())
                ->onlyOnIndex(),

            Text::make('First Name')->rules('required', 'max:255')->hideFromIndex(),
            Text::make('Last Name')->rules('required', 'max:255')->hideFromIndex(),
            Text::make('Phone')->rules('nullable', 'max:32')->hideFromIndex(),
            Text::make('Address')->rules('nullable', 'max:255')->hideFromIndex(),
            Text::make('Firm Name')->rules('nullable', 'max:255')->sortable(),

            new Panel('Agency Membership', $this->agencyFields()),

            new Panel('License', $this->licenseFields()),

            new Panel('Membership Certificate', $this->certificateFields()),

            new Panel('Membership Payment', $this->paymentFields()),

            new Panel('Notification Preferences', $this->notificationFields()),
        ];
    }

    /**
     * Agency spec 7: who is on the roster and how far their invitation got. Agency people have
     * no license number, type or expiry date (Agency spec 3.5); employees have no NPN or state.
     *
     * @return array<int, Field>
     */
    private function rosterFields(): array
    {
        $profile = $this->resource;
        $licensed = ! $profile->isAgencyEmployee();

        return [
            ID::make()->sortable(),
            Text::make('Name', fn () => $profile->fullName()),
            Text::make('Email', fn () => $profile->user?->email),
            Text::make('Person Type', fn () => $profile->person_type?->label()),
            Badge::make('Agency Role', fn () => $profile->agency_role?->value)
                ->map([AgencyRole::Principal->value => 'success', AgencyRole::Member->value => 'info'])
                ->labels(self::enumOptions(AgencyRole::cases())),
            Text::make('NPN', fn () => $licensed ? $profile->npn : null),
            Text::make('Resident State', fn () => $licensed ? (UsStates::ALL[$profile->license_state] ?? null) : null),
            Text::make('Invite Status', fn () => $profile->invite_status?->label()),
            Text::make('Status', fn () => $profile->status?->label()),
        ];
    }

    /**
     * License number, type and expiry date are not collected for agency people (Agency spec
     * 3.5): hidden for them, the columns kept for individual members (expand-then-contract).
     */
    private static function individualLicenseOnly(Field $field): Field
    {
        $inAgency = fn (NovaRequest $request, $resource) => $resource->agency_id !== null;

        return $field
            ->resolveUsing(fn ($value, $resource) => $resource->agency_id !== null ? null : $value)
            ->hideFromDetail($inAgency)
            ->hideWhenUpdating($inAgency);
    }

    /**
     * @return array<int, Field>
     */
    private function licenseFields(): array
    {
        return [
            // Encrypted at rest: searchable by exact value, not sortable.
            Text::make('Member ID', 'member_number')->exceptOnForms(),

            Text::make('NPN', 'npn')->rules('nullable', 'max:20'),

            self::individualLicenseOnly(Text::make('License Number')->rules('nullable', 'max:50')->sortable()),

            Select::make('License State')
                ->options(UsStates::ALL)
                ->displayUsingLabels()
                ->rules('nullable', 'size:2')
                ->filterable(),

            self::individualLicenseOnly(Select::make('License Type')
                ->options(array_combine(\App\Models\MemberProfile::LICENSE_TYPES, \App\Models\MemberProfile::LICENSE_TYPES))
                ->rules('nullable', 'max:100')
                ->filterable()),

            self::individualLicenseOnly(Date::make('License Expires At')
                ->rules('nullable', 'date')
                ->sortable()
                ->filterable()),

            // Agency employees hold no license: no status (shown as a dash).
            Badge::make('License Status')
                ->resolveUsing(fn ($value) => ($value instanceof LicenseStatus ? $value->value : $value) ?? self::NO_LICENSE)
                ->map([
                    LicenseStatus::Active->value => 'success',
                    LicenseStatus::Expiring->value => 'warning',
                    LicenseStatus::Expired->value => 'danger',
                    self::NO_LICENSE => 'info',
                ])
                ->labels(collect(LicenseStatus::cases())
                    ->mapWithKeys(fn (LicenseStatus $status) => [$status->value => Str::headline($status->value)])
                    ->put(self::NO_LICENSE, '—')
                    ->all())
                ->withIcons()
                ->sortable()
                ->filterable(),

            Text::make('Certificate Code')
                ->exceptOnForms()
                ->copyable(),
        ];
    }

    /**
     * The member's certificate block ("Certificate" in the sidebar scrolls here): a download
     * button while the certificate is available, otherwise the reason.
     *
     * @return array<int, Field>
     */
    private function certificateFields(): array
    {
        return [
            Text::make('Certificate', function () {
                $profile = $this->resource;

                try {
                    app(CertificateGeneratorService::class)->assertEligible($profile);
                } catch (CertificateUnavailableException $e) {
                    return e($e->getMessage());
                }

                return sprintf(
                    '<a href="%s" class="ppx-certificate-download">Download certificate</a>',
                    e(route('certificates.open', $profile)),
                );
            })
                ->asHtml()
                ->onlyOnDetail()
                ->canSee(fn (Request $request) => static::viewerIsMember($request)
                    && Gate::forUser($request->user())->check('downloadCertificate', $this->resource)),
        ];
    }

    /**
     * Staff move people between agencies here; the principal is reassigned on the agency.
     * Members see their own agency fields read-only.
     *
     * @return array<int, Field>
     */
    private function agencyFields(): array
    {
        $readonlyForMembers = fn (NovaRequest $request) => ! static::viewerIsStaff($request);

        return [
            Badge::make('Member Type')
                ->map([MemberType::Individual->value => 'info', MemberType::AgencyMember->value => 'success'])
                ->labels(self::enumOptions(MemberType::cases()))
                ->filterable(),

            BelongsTo::make('Agency', 'agency', Agency::class)
                ->nullable()
                ->searchable()
                ->canSee(fn (Request $request) => (bool) $request->user()?->checkPermissionTo('agencies.view'))
                ->readonly(fn () => $this->resource->isAgencyPrincipal())
                ->help('To move the principal, reassign the principal on the agency first.')
                ->hideFromIndex()
                ->filterable(),

            Text::make('Agency Role', fn () => $this->resource->agency_role?->label())
                ->exceptOnForms()
                ->hideFromIndex(),

            Select::make('Person Type')
                ->options(self::enumOptions(PersonType::cases()))
                ->displayUsingLabels()
                ->nullable()
                ->rules('nullable', Rule::enum(PersonType::class))
                ->help('Agency employees have no NPN and can join only through an agency.')
                ->readonly($readonlyForMembers)
                ->hideFromIndex(),

            Text::make('Invite Status', fn () => $this->resource->invite_status?->label())
                ->exceptOnForms()
                ->hideFromIndex(),

            Select::make('Membership Status', 'status')
                ->options(self::enumOptions(MemberStatus::cases()))
                ->displayUsingLabels()
                ->rules('required', Rule::enum(MemberStatus::class))
                ->default(MemberStatus::Active->value)
                ->readonly($readonlyForMembers)
                ->hideFromIndex()
                ->filterable(),
        ];
    }

    /**
     * @param  list<MemberType|PersonType|MemberStatus|AgencyRole>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all();
    }

    /**
     * Read-only: the status comes from Stripe webhooks (Spec 7).
     *
     * @return array<int, Field>
     */
    private function paymentFields(): array
    {
        return [
            Badge::make('Payment Status')
                ->map([
                    PaymentStatus::Active->value => 'success',
                    PaymentStatus::PastDue->value => 'warning',
                    PaymentStatus::Canceled->value => 'danger',
                    PaymentStatus::Unknown->value => 'info',
                ])
                ->labels(collect(PaymentStatus::cases())
                    ->mapWithKeys(fn (PaymentStatus $status) => [$status->value => $status->label()])
                    ->all())
                ->withIcons()
                ->sortable()
                ->filterable(),

            DateTime::make('Payment Status Updated At')
                ->exceptOnForms()
                ->hideFromIndex(),

            Text::make('Grace Period Ends', fn () => $this->resource->paymentGraceEndsAt()?->toFormattedDateString())
                ->onlyOnDetail(),

            Text::make('Paid Through', fn () => $this->resource->paid_through_at?->toFormattedDateString())
                ->onlyOnDetail(),

            Text::make('Stripe Customer ID', 'stripe_customer_id')
                ->onlyOnDetail()
                ->canSee(fn (Request $request) => static::viewerIsStaff($request)),
        ];
    }

    /**
     * One toggle per category; stored in notification_settings after the profile is saved.
     *
     * @return array<int, Field>
     */
    private function notificationFields(): array
    {
        return collect(NotificationCategory::cases())->map(function (NotificationCategory $category) {
            return Boolean::make(Str::headline($category->value), "notify_{$category->value}")
                ->hideFromIndex()
                ->resolveUsing(function () use ($category) {
                    $setting = $this->resource->user?->notificationSettings
                        ->first(fn (NotificationSetting $setting) => $setting->category === $category);

                    // Categories are opt-out: no row means enabled.
                    return $setting?->enabled ?? true;
                })
                ->readonly(fn (NovaRequest $request) => ! $this->canManageNotifications($request))
                ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) use ($category) {
                    if (! $request->exists($requestAttribute) || ! $this->canManageNotifications($request, $model)) {
                        return null;
                    }

                    $enabled = $request->boolean($requestAttribute);

                    return fn () => $model->user->notificationSettings()->updateOrCreate(
                        ['category' => $category],
                        ['enabled' => $enabled],
                    );
                });
        })->all();
    }

    private function canManageNotifications(Request $request, ?\App\Models\MemberProfile $profile = null): bool
    {
        $owner = ($profile ?? $this->resource)->user;

        return $owner !== null && Gate::forUser($request->user())->check('manageFor', [NotificationSetting::class, $owner]);
    }

    public function actions(NovaRequest $request): array
    {
        $canDownload = fn (NovaRequest $request, \App\Models\MemberProfile $profile) => Gate::forUser($request->user())
            ->check('downloadCertificate', $profile);

        return [
            GenerateCertificate::make()
                ->showInline()
                ->canSee(fn (Request $request) => static::viewerIsStaff($request))
                ->canRun($canDownload),

            DownloadMyCertificate::make()
                ->showInline()
                ->canSee(fn (Request $request) => static::viewerIsMember($request))
                ->canRun(fn (NovaRequest $request, \App\Models\MemberProfile $profile) => $profile->user_id === $request->user()->id
                    && $canDownload($request, $profile)),
        ];
    }
}
