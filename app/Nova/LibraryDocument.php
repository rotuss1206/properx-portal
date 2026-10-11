<?php

namespace App\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    public const DISK = 'local';

    public const DIRECTORY = 'library';

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

            File::make('File', 'file_path', self::DISK)
                ->path(self::DIRECTORY)
                ->acceptedTypes('.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.mp4')
                ->creationRules('required')
                ->rules('max:51200')
                ->download(fn ($request, $model) => Storage::disk(self::DISK)->download($model->file_path, basename($model->file_path)))
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
