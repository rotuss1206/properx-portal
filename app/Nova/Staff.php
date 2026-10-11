<?php

namespace App\Nova;

use App\Enums\Role;
use App\Models\User;
use App\Nova\Concerns\AuthorizesUserResource;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Auth\PasswordValidationRules;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Staff accounts (Executive Director only, Spec §3.7).
 *
 * @extends \App\Nova\Resource<User>
 */
class Staff extends Resource
{
    use AuthorizesUserResource, PasswordValidationRules;

    public static $model = User::class;

    public static $title = 'name';

    public static $search = ['name', 'email'];

    public static $group = 'Administration';

    protected static string $viewAnyAbility = 'viewAnyStaff';

    protected static string $createAbility = 'createStaff';

    public static function label()
    {
        return 'Staff';
    }

    public static function singularLabel()
    {
        return 'Staff Member';
    }

    public static function uriKey()
    {
        return 'staff';
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->role(self::staffRoleNames())->with('roles');
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->role(self::staffRoleNames());
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('Email')
                ->sortable()
                ->rules('required', 'email', 'max:254')
                ->creationRules('unique:users,email')
                ->updateRules('unique:users,email,{{resourceId}}'),

            Select::make('Role')
                ->options(array_combine(self::staffRoleNames(), self::staffRoleNames()))
                ->rules('required')
                ->resolveUsing(fn ($value, $user) => $user->getRoleNames()->first())
                // The Executive Director role cannot be revoked from the UI.
                ->readonly(fn () => $this->resource->exists && $this->resource->isExecutiveDirector())
                ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                    $role = $request->input($requestAttribute);

                    if (! in_array($role, self::staffRoleNames(), true)
                        || ($model->exists && $model->isExecutiveDirector())) {
                        return null;
                    }

                    return fn () => $model->syncRoles([$role]);
                })
                ->filterable(fn (NovaRequest $request, Builder $query, $value) => $query->role($value)),

            Boolean::make('2FA enabled', fn () => $this->resource->hasConfirmedTwoFactor())
                ->exceptOnForms(),

            DateTime::make('Last login', 'last_login_at')
                ->exceptOnForms()
                ->sortable(),

            Password::make('Password')
                ->onlyOnForms()
                ->creationRules($this->passwordRules())
                ->updateRules($this->optionalPasswordRules()),
        ];
    }

    /**
     * @return list<string>
     */
    private static function staffRoleNames(): array
    {
        return array_map(fn (Role $role) => $role->value, Role::staff());
    }
}
