<?php

namespace App\Nova;

use App\Services\DocumentDownloader;
use App\Services\Documents\DocumentVault;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Compliance & training library. Members see published documents only.
 *
 * @extends \App\Nova\Resource<\App\Models\LibraryDocument>
 */
class LibraryDocument extends Resource
{
    public static $model = \App\Models\LibraryDocument::class;

    public static $title = 'title';

    public static $search = ['title', 'description'];

    public static $group = 'Documents';

    public static function label()
    {
        return 'Library';
    }

    public static function singularLabel()
    {
        return 'Library Document';
    }

    public static function group()
    {
        return static::viewerIsMember(request()) ? 'Resources' : static::$group;
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $request->user()?->checkPermissionTo('library.update')
            ? $query
            : $query->where('published', true);
    }

    public static function beforeCreate(NovaRequest $request, Model $model)
    {
        $model->uploaded_at = now();
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Title')
                ->rules('required', 'max:255')
                ->sortable(),

            Select::make('Category')
                ->options(array_combine(\App\Models\LibraryDocument::CATEGORIES, \App\Models\LibraryDocument::CATEGORIES))
                ->rules('required')
                ->sortable()
                ->filterable(),

            Textarea::make('Description')
                ->rules('nullable', 'max:5000')
                ->alwaysShow(),

            File::make('File', 'file_path', DocumentVault::diskName())
                ->acceptedTypes(DocumentVault::acceptedExtensions())
                ->creationRules('required')
                ->rules(DocumentVault::uploadRules())
                ->store(fn (Request $request, $model, $attribute, $requestAttribute) => [
                    'file_path' => app(DocumentVault::class)
                        ->store($request->file($requestAttribute), \App\Models\LibraryDocument::DIRECTORY, encrypt: false)
                        ->path,
                ])
                ->download(fn ($request, $model) => app(DocumentDownloader::class)->link($model))
                ->prunable()
                ->hideFromIndex(),

            Boolean::make('Published')
                ->sortable()
                ->filterable()
                ->canSee(fn (Request $request) => (bool) $request->user()?->checkPermissionTo('library.update')),

            DateTime::make('Uploaded At')
                ->exceptOnForms()
                ->sortable()
                ->filterable(),
        ];
    }
}
