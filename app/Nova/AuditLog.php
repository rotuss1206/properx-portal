<?php

namespace App\Nova;

use Illuminate\Support\Str;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Read-only audit trail (see AuditLogPolicy).
 *
 * @extends \App\Nova\Resource<\App\Models\AuditLog>
 */
class AuditLog extends Resource
{
    public static $model = \App\Models\AuditLog::class;

    public static $title = 'id';

    public static $search = ['action', 'ip_address'];

    public static $group = 'Logs';

    public static $globallySearchable = false;

    public static $with = ['user'];

    public static function label()
    {
        return 'Audit Log';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            DateTime::make('Time', 'created_at')->sortable()->filterable(),

            // Plain text: the actor may be staff or a member (or deleted).
            Text::make('User', fn () => $this->resource->user
                ? "{$this->resource->user->name} <{$this->resource->user->email}>"
                : '—'),

            Select::make('Action')
                ->options(collect(\App\Models\AuditLog::ACTIONS)
                    ->mapWithKeys(fn (string $action) => [$action => Str::headline($action)])
                    ->all())
                ->displayUsingLabels()
                ->sortable()
                ->filterable(),

            Text::make('Resource', fn () => $this->resource->resource_type
                ? class_basename($this->resource->resource_type)." #{$this->resource->resource_id}"
                : '—'),

            Text::make('IP Address'),

            Text::make('User Agent')->onlyOnDetail(),
        ];
    }
}
