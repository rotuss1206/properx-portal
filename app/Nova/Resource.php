<?php

namespace App\Nova;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource as NovaResource;
use Laravel\Scout\Builder as ScoutBuilder;

abstract class Resource extends NovaResource
{
    /**
     * Build an "index" query for the given resource.
     */
    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query;
    }

    /**
     * Build a Scout search query for the given resource.
     */
    public static function scoutQuery(NovaRequest $request, ScoutBuilder $query): ScoutBuilder
    {
        return $query;
    }

    /**
     * Build a "detail" query for the given resource.
     */
    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return parent::detailQuery($request, $query);
    }

    /**
     * Build a "relatable" query for the given resource.
     *
     * This query determines which instances of the model may be attached to other resources.
     */
    public static function relatableQuery(NovaRequest $request, Builder $query): Builder
    {
        return parent::relatableQuery($request, $query);
    }

    /**
     * Limit the query to the current user's own records unless they hold $staffPermission.
     */
    protected static function ownRecordsUnless(Request $request, Builder $query, string $staffPermission, string $ownerColumn = 'user_id'): Builder
    {
        $user = $request->user();

        if ($user?->checkPermissionTo($staffPermission)) {
            return $query;
        }

        return $query->where($query->qualifyColumn($ownerColumn), $user?->getKey());
    }

    protected static function viewerIsMember(Request $request): bool
    {
        return (bool) $request->user()?->isMember();
    }

    protected static function viewerIsStaff(Request $request): bool
    {
        return (bool) $request->user()?->isStaff();
    }
}
