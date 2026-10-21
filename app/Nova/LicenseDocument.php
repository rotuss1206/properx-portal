<?php

namespace App\Nova;

use App\Enums\VerificationStatus;
use App\Nova\Actions\RejectLicenseDocument;
use App\Nova\Actions\VerifyLicenseDocument;
use App\Services\DocumentDownloader;
use App\Services\Documents\DocumentVault;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * @extends \App\Nova\Resource<\App\Models\LicenseDocument>
 */
class LicenseDocument extends Resource
{
    public static $model = \App\Models\LicenseDocument::class;

    public static $title = 'original_filename';

    public static $search = ['original_filename'];

    public static $group = 'Documents';

    public static function label()
    {
        return static::viewerIsMember(request()) ? 'My License Documents' : 'License Documents';
    }

    public static function group()
    {
        return static::viewerIsMember(request()) ? 'My Account' : static::$group;
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::ownRecordsUnless($request, $query, 'license-documents.view');
    }

    /**
     * Members always upload for themselves, whatever the request says.
     */
    public static function beforeCreate(NovaRequest $request, Model $model)
    {
        if (! $request->user()->checkPermissionTo('license-documents.verify')) {
            $model->user_id = $request->user()->getKey();
        }

        $model->uploaded_at = now();
        $model->verification_status = VerificationStatus::Pending;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Member', 'user', Member::class)
                ->searchable()
                ->rules('required')
                ->canSee(fn (Request $request) => static::viewerIsStaff($request))
                ->readonly(fn () => $this->resource->exists),

            File::make('File', 'stored_filename', DocumentVault::diskName())
                ->acceptedTypes(DocumentVault::acceptedExtensions())
                ->creationRules('required')
                ->rules(DocumentVault::uploadRules())
                ->store(function (Request $request, $model, $attribute, $requestAttribute) {
                    $document = app(DocumentVault::class)
                        ->store($request->file($requestAttribute), \App\Models\LicenseDocument::DIRECTORY, encrypt: true);

                    return [
                        'stored_filename' => $document->path,
                        'original_filename' => $document->originalName,
                        'mime_type' => $document->mimeType,
                        'size' => $document->size,
                    ];
                })
                ->download(fn ($request, $model) => app(DocumentDownloader::class)->link($model))
                ->prunable()
                ->hideFromIndex()
                ->hideWhenUpdating(),

            Text::make('File Name', 'original_filename')
                ->exceptOnForms()
                ->sortable(),

            Text::make('Type', 'mime_type')->onlyOnDetail(),

            Text::make('Size', fn () => Number::fileSize($this->resource->size))->exceptOnForms(),

            DateTime::make('Uploaded At')
                ->exceptOnForms()
                ->sortable()
                ->filterable(),

            Badge::make('Status', 'verification_status')
                ->map([
                    VerificationStatus::Pending->value => 'info',
                    VerificationStatus::Approved->value => 'success',
                    VerificationStatus::Rejected->value => 'danger',
                ])
                ->labels(collect(VerificationStatus::cases())
                    ->mapWithKeys(fn (VerificationStatus $status) => [$status->value => Str::headline($status->value)])
                    ->all())
                ->withIcons()
                ->sortable()
                ->filterable(),

            Textarea::make('Admin Notes')
                ->hideWhenCreating()
                ->rules('nullable', 'max:2000')
                ->alwaysShow(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        $canVerify = fn (Request $request) => (bool) $request->user()?->checkPermissionTo('license-documents.verify');

        return [
            VerifyLicenseDocument::make()->showInline()->canSee($canVerify),
            RejectLicenseDocument::make()->showInline()->canSee($canVerify),
        ];
    }
}
