<?php

namespace App\Nova;

use App\Services\DocumentDownloader;
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

    public static $search = ['original_filename', 'document_type'];

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
                ->rules('required')
                ->sortable()
                ->filterable(),

            Number::make('Tax Year')
                ->min(2000)
                ->max(now()->year)
                ->rules('required', 'integer', 'min:2000', 'max:'.now()->year)
                ->sortable()
                ->filterable(),

            File::make('File', 'stored_filename', \App\Models\TaxDocument::disk())
                ->path(\App\Models\TaxDocument::DIRECTORY)
                ->storeOriginalName('original_filename')
                ->acceptedTypes('.pdf')
                ->creationRules('required')
                ->rules('mimes:pdf', 'max:10240')
                ->download(function ($request, $model, $disk) {
                    $response = app(DocumentDownloader::class)
                        ->download($model, $disk, $model->stored_filename, $model->original_filename);

                    // Track when the member themselves retrieves the document (ТЗ, розділ 3.1).
                    if ($request->user()->is($model->user)) {
                        $model->forceFill(['downloaded_at' => now()])->save();
                    }

                    return $response;
                })
                ->prunable()
                ->hideFromIndex(),

            Text::make('File Name', 'original_filename')->exceptOnForms(),

            DateTime::make('Uploaded At')->exceptOnForms()->sortable()->filterable(),

            DateTime::make('Downloaded At')->exceptOnForms()->sortable(),
        ];
    }
}
