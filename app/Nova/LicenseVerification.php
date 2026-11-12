<?php

namespace App\Nova;

use App\Enums\LicenseVerification as Verification;
use App\Models\LicenseDocument as LicenseDocumentModel;
use App\Nova\Actions\MarkLicenseNeedsAttention;
use App\Nova\Actions\MarkLicenseVerified;
use App\Nova\Filters\LicenseVerificationStatus;
use App\Support\UsStates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

/**
 * The license verification queue (Agency spec 8.3): licensed producers and principals of agencies
 * and individual members whose NPN and resident state staff check in NIPR or the state registry.
 * Pending review by default; the status filter shows the others. Read-only apart from the two
 * actions. Membership Coordinator and Executive Director (permission members.license.verify).
 *
 * @extends \App\Nova\Resource<\App\Models\MemberProfile>
 */
class LicenseVerification extends Resource
{
    public static $model = \App\Models\MemberProfile::class;

    public static $group = 'Members';

    public static $search = ['first_name', 'last_name'];

    public static $with = ['agency', 'user'];

    public static $perPageOptions = [25, 50, 100];

    public static function label()
    {
        return 'License verification';
    }

    public static function singularLabel()
    {
        return 'License verification';
    }

    public static function uriKey()
    {
        return 'license-verification';
    }

    public function title()
    {
        return $this->resource->fullName();
    }

    /**
     * Names match partially; the encrypted NPN exactly, through its blind index.
     */
    protected static function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search) {
            static::initializeSearch($query, $search, static::$search);
            $query->orWhere(fn (Builder $query) => $query->whereBlindIndex('npn', $search));
        });
    }

    /**
     * Licensed people only (employees hold no license); oldest in the queue first unless sorted.
     */
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->licensed()
            ->whereNotNull('license_verification')
            ->withCount(['licenseDocuments', 'licenseDocuments as pending_documents_count' => fn (Builder $query) => $query->where('verification_status', 'pending')])
            ->when(blank($request->get('orderBy')), fn (Builder $query) => $query->reorder()->orderBy('license_verification_changed_at')->orderBy('id'));
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return parent::detailQuery($request, $query->licensed()->whereNotNull('license_verification'));
    }

    public static function canVerify(Request $request): bool
    {
        return (bool) Nova::user($request)?->checkPermissionTo('members.license.verify');
    }

    public static function authorizedToViewAny(Request $request)
    {
        return static::canVerify($request);
    }

    public function authorizeToViewAny(Request $request)
    {
        throw_unless(static::canVerify($request), AuthorizationException::class);
    }

    public static function authorizedToCreate(Request $request)
    {
        return false;
    }

    /**
     * Viewing only: the status changes through the actions, the rest on the member profile.
     */
    public function authorizedTo(Request $request, string $ability): bool
    {
        return $ability === 'view' && static::canVerify($request) && parent::authorizedTo($request, $ability);
    }

    public function authorizeTo(Request $request, string $ability): void
    {
        throw_unless($this->authorizedTo($request, $ability), AuthorizationException::class);
    }

    public function authorizedToReplicate(Request $request)
    {
        return false;
    }

    public function authorizedToRunAction(NovaRequest $request, Action $action)
    {
        return static::canVerify($request);
    }

    public function fields(NovaRequest $request): array
    {
        $profile = $this->resource;

        return [
            ID::make()->hideFromIndex(),

            Text::make('Name', fn () => $profile->fullName()),

            BelongsTo::make('Agency', 'agency', Agency::class)
                ->nullable()
                ->filterable()
                ->help('Empty for individual members.'),

            Text::make('Email', fn () => $profile->user?->email)->onlyOnDetail(),

            Text::make('Role', fn () => $profile->agency_id === null ? 'Individual member' : ($profile->isAgencyPrincipal() ? 'Agency principal' : 'Licensed producer'))
                ->onlyOnDetail(),

            // Encrypted at rest: shown, searchable by exact value, not sortable.
            Text::make('NPN', 'npn')->copyable(),

            Select::make('State', 'license_state')
                ->options(UsStates::ALL)
                ->displayUsingLabels()
                ->sortable()
                ->filterable(),

            Text::make('Documents', fn () => $this->documentsSummary())
                ->asHtml(),

            Badge::make('Status', 'license_verification')
                ->resolveUsing(fn ($value) => $value instanceof Verification ? $value->value : $value)
                ->map([
                    Verification::PendingReview->value => 'warning',
                    Verification::Verified->value => 'success',
                    Verification::NeedsAttention->value => 'danger',
                ])
                ->labels(collect(Verification::cases())->mapWithKeys(fn (Verification $status) => [$status->value => $status->label()])->all())
                ->withIcons(),

            Text::make('What to fix', 'license_verification_note')
                ->onlyOnDetail()
                ->canSee(fn () => $profile->license_verification === Verification::NeedsAttention),

            DateTime::make('Since', 'license_verification_changed_at')
                ->displayUsing(fn ($date) => $date?->toFormattedDateString())
                ->sortable()
                ->filterable()
                ->help('When the license entered this status.'),

            Text::make('Member Profile', fn () => sprintf(
                '<a class="link-default" href="%s">Open the full profile</a>',
                e(Nova::url('/resources/'.MemberProfile::uriKey().'/'.$profile->getKey())),
            ))->asHtml()->onlyOnDetail(),
        ];
    }

    /**
     * License documents uploaded by this person, if any (agency people upload none): a count on
     * the list, links on the detail page.
     */
    private function documentsSummary(): string
    {
        $profile = $this->resource;
        $count = $profile->license_documents_count ?? null;

        if ($count === null) {
            $documents = LicenseDocumentModel::query()->where('user_id', $profile->user_id)->latest()->get(['id', 'verification_status', 'created_at']);

            if ($documents->isEmpty()) {
                return '—';
            }

            return $documents->map(fn (LicenseDocumentModel $document) => sprintf(
                '<a class="link-default" href="%s">#%d · %s · %s</a>',
                e(Nova::url('/resources/'.LicenseDocument::uriKey().'/'.$document->getKey())),
                $document->getKey(),
                e($document->verification_status?->label() ?? ''),
                e($document->created_at?->toFormattedDateString() ?? ''),
            ))->implode('<br>');
        }

        if ((int) $count === 0) {
            return '—';
        }

        $pending = (int) ($profile->pending_documents_count ?? 0);

        return e($count.($pending > 0 ? " ({$pending} pending)" : ''));
    }

    /**
     * @return array<int, Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [new LicenseVerificationStatus];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            MarkLicenseVerified::make()
                ->showInline()
                ->canSee(fn (Request $request) => static::canVerify($request))
                ->canRun(fn (NovaRequest $request, $profile) => static::canVerify($request)),

            MarkLicenseNeedsAttention::make()
                ->sole()
                ->showInline()
                ->canSee(fn (Request $request) => static::canVerify($request))
                ->canRun(fn (NovaRequest $request, $profile) => static::canVerify($request)),
        ];
    }
}
