<?php

namespace App\Nova;

use App\Services\DocumentDownloader;
use App\Services\Documents\DocumentVault;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Uploaded by the Compliance Specialist and linked to a member.
 *
 * @extends \App\Nova\Resource<\App\Models\TaxDocument>
 */
class TaxDocument extends Resource
{
    public static $model = \App\Models\TaxDocument::class;

    public static $title = 'original_filename';

    // Type, year and file name are encrypted at rest: no SQL search, sorting or filtering on them.
    public static $search = ['id'];

    public static $group = 'Documents';

    public static function label()
    {
        return static::viewerIsMember(request()) ? 'My Tax Documents' : 'Tax Documents';
    }

    public static function group()
    {
        return static::viewerIsMember(request()) ? 'My Account' : static::$group;
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return static::ownRecordsUnless($request, $query, 'tax-documents.view');
    }

    public static function beforeCreate(NovaRequest $request, Model $model)
    {
        $model->uploaded_at = now();
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Member', 'user', Member::class)
                ->searchable()
                ->rules('required')
                ->canSee(fn (Request $request) => static::viewerIsStaff($request)),

            Select::make('Document Type')
                ->options(array_combine(\App\Models\TaxDocument::DOCUMENT_TYPES, \App\Models\TaxDocument::DOCUMENT_TYPES))
                ->rules('required'),

            Number::make('Tax Year')
                ->min(2000)
                ->max(now()->year)
                ->rules('required', 'integer', 'min:2000', 'max:'.now()->year),

            File::make('File', 'stored_filename', DocumentVault::diskName())
                ->acceptedTypes(DocumentVault::acceptedExtensions())
                ->creationRules('required')
                ->rules(DocumentVault::uploadRules())
                ->store(function (Request $request, $model, $attribute, $requestAttribute) {
                    $document = app(DocumentVault::class)
                        ->store($request->file($requestAttribute), \App\Models\TaxDocument::DIRECTORY, encrypt: true);

                    return [
                        'stored_filename' => $document->path,
                        'original_filename' => $document->originalName,
                    ];
                })
                // downloaded_at is recorded when the signed route actually streams the file.
                ->download(fn ($request, $model) => app(DocumentDownloader::class)->link($model))
                ->prunable()
                ->hideFromIndex(),

            Text::make('File Name', 'original_filename')->exceptOnForms(),

            DateTime::make('Uploaded At')->exceptOnForms()->sortable()->filterable(),

            DateTime::make('Downloaded At')->exceptOnForms()->sortable(),
        ];
    }
}
