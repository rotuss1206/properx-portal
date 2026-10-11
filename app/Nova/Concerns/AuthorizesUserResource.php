<?php

namespace App\Nova\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Nova;

/**
 * Staff and Member resources share the User model (and UserPolicy), so their
 * class-level abilities are mapped to resource-specific policy methods.
 *
 * @property-read string $viewAnyAbility
 * @property-read string $createAbility
 */
trait AuthorizesUserResource
{
    public static function authorizedToViewAny(Request $request)
    {
        return Gate::forUser(Nova::user($request))->check(static::$viewAnyAbility, static::$model);
    }

    public function authorizeToViewAny(Request $request)
    {
        throw_unless(static::authorizedToViewAny($request), AuthorizationException::class);
    }

    public static function authorizedToCreate(Request $request)
    {
        return Gate::forUser(Nova::user($request))->check(static::$createAbility, static::$model);
    }
}
