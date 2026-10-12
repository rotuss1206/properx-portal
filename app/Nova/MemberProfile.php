<?php

namespace App\Nova;

use App\Enums\LicenseStatus;
use App\Enums\NotificationCategory;
use App\Enums\PaymentStatus;
use App\Models\NotificationSetting;
use App\Nova\Actions\DownloadMyCertificate;
use App\Nova\Actions\GenerateCertificate;
use App\Support\UsStates;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

    public static $search = ['first_name', 'last_name', 'npn', 'license_number'];

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
        return static::ownRecordsUnless($request, $query, 'members.view');
    }

    public static function relatableQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::ownRecordsUnless($request, $query, 'members.view');
    }

    public function fields(NovaRequest $request): array
    {
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

            new Panel('License', $this->licenseFields()),

            new Panel('Membership Payment', $this->paymentFields()),

            new Panel('Notification Preferences', $this->notificationFields()),
        ];
    }

    /**
     * @return array<int, Field>
     */
    private function licenseFields(): array
    {
        return [
            Text::make('NPN', 'npn')->rules('nullable', 'max:20')->sortable(),

            Text::make('License Number')->rules('nullable', 'max:50')->sortable(),

            Select::make('License State')
                ->options(UsStates::ALL)
                ->displayUsingLabels()
                ->rules('nullable', 'size:2')
                ->filterable(),

            Select::make('License Type')
                ->options(array_combine(\App\Models\MemberProfile::LICENSE_TYPES, \App\Models\MemberProfile::LICENSE_TYPES))
                ->rules('nullable', 'max:100')
                ->filterable(),

            Date::make('License Expires At')
                ->rules('nullable', 'date')
                ->sortable()
                ->filterable(),

            Badge::make('License Status')
                ->map([
                    LicenseStatus::Active->value => 'success',
                    LicenseStatus::Expiring->value => 'warning',
                    LicenseStatus::Expired->value => 'danger',
                ])
                ->labels(collect(LicenseStatus::cases())
                    ->mapWithKeys(fn (LicenseStatus $status) => [$status->value => Str::headline($status->value)])
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
