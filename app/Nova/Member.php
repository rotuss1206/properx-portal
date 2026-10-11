<?php

namespace App\Nova;

use App\Enums\Role;
use App\Models\User;
use App\Nova\Concerns\AuthorizesUserResource;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Auth\PasswordValidationRules;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\HasOne;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Member login accounts. Profile data lives in MemberProfile.
 *
 * @extends \App\Nova\Resource<User>
 */
class Member extends Resource
{
    use AuthorizesUserResource, PasswordValidationRules;

    public static $model = User::class;

    public static $title = 'name';

    public static $search = ['name', 'email'];

    public static $group = 'Members';

    protected static string $viewAnyAbility = 'viewAnyMembers';

    protected static string $createAbility = 'createMembers';

    public static function label()
    {
        return 'Member Accounts';
    }

    public static function singularLabel()
    {
        return 'Member Account';
    }

    public static function uriKey()
    {
        return 'members';
    }

    public static function indexQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->role(Role::Member->value);
    }

    public static function detailQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->role(Role::Member->value);
    }

    public static function relatableQuery(NovaRequest $request, Builder $query): Builder
    {
        return $query->role(Role::Member->value);
    }

    public static function afterCreate(NovaRequest $request, Model $model)
    {
        $model->assignRole(Role::Member->value);
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

            Boolean::make('2FA enabled', fn () => $this->resource->hasConfirmedTwoFactor())
                ->exceptOnForms(),

            DateTime::make('Last login', 'last_login_at')
                ->exceptOnForms()
                ->sortable(),

            Password::make('Password')
                ->onlyOnForms()
                ->creationRules($this->passwordRules())
                ->updateRules($this->optionalPasswordRules()),

            HasOne::make('Profile', 'memberProfile', MemberProfile::class),

            HasMany::make('License Documents', 'licenseDocuments', LicenseDocument::class),

            HasMany::make('Tax Documents', 'taxDocuments', TaxDocument::class),

            HasMany::make('Reminder History', 'reminderLogs', ReminderLog::class),
        ];
    }
}
