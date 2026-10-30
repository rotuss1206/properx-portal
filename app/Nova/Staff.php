<?php

namespace App\Nova;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\StaffAccountCreated;
use App\Nova\Concerns\AuthorizesUserResource;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Staff accounts (Executive Director only, Spec 3.7).
 *
 * @extends \App\Nova\Resource<User>
 */
class Staff extends Resource
{
    use AuthorizesUserResource;

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

    /**
     * Safety net: Nova runs this inside the create transaction, so an account that
     * somehow ended up without a staff role is rolled back instead of being saved.
     */
    public static function afterCreate(NovaRequest $request, Model $model)
    {
        if (! $model->hasAnyRole(self::staffRoleNames())) {
            throw ValidationException::withMessages(['role' => 'A staff account must have a staff role.']);
        }

        // They choose their own password through the emailed link (sent after commit).
        $model->notify(new StaffAccountCreated);
    }

    /**
     * Nobody types a password for a new staff member: the account starts with a random,
     * unknown one until they set theirs from the emailed link.
     */
    public static function fill(NovaRequest $request, $model): array
    {
        if (! $model->exists) {
            $model->password = Str::password(64);
        }

        return parent::fill($request, $model);
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

            // Forms offer only the roles staff may hand out: the Executive Director role is
            // assigned and revoked with app:assign-executive-director, never through Nova.
            Select::make('Role')
                ->options(function () use ($request) {
                    $roles = $request->isFormRequest() && ! $this->isExecutiveDirector()
                        ? self::assignableRoleNames()
                        : self::staffRoleNames();

                    return array_combine($roles, $roles);
                })
                ->rules('required', Rule::in(self::assignableRoleNames()))
                ->resolveUsing(fn ($value, $user) => $user->getRoleNames()->first())
                // The Executive Director's role is shown, never changed or removed here.
                ->readonly(fn () => $this->isExecutiveDirector())
                ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) {
                    $role = (string) $request->input($requestAttribute);

                    if (! Gate::forUser($request->user())->allows('assignRole', [$model, $role])) {
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
        ];
    }

    private function isExecutiveDirector(): bool
    {
        return $this->resource instanceof User && $this->resource->exists && $this->resource->isExecutiveDirector();
    }

    /**
     * @return list<string>
     */
    private static function staffRoleNames(): array
    {
        return array_map(fn (Role $role) => $role->value, Role::staff());
    }

    /**
     * @return list<string>
     */
    private static function assignableRoleNames(): array
    {
        return array_map(fn (Role $role) => $role->value, Role::assignableStaff());
    }
}
