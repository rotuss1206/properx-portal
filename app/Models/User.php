<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
#[ObservedBy(UserObserver::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(array_map(fn (Role $role) => $role->value, Role::staff()));
    }

    public function isMember(): bool
    {
        return $this->hasRole(Role::Member->value);
    }

    /**
     * A member whose dues are canceled or past the grace period (Spec 7).
     */
    public function hasRestrictedMembership(): bool
    {
        return $this->isMember() && (bool) $this->memberProfile?->hasRestrictedMembership();
    }

    public function isExecutiveDirector(): bool
    {
        return $this->hasRole(Role::ExecutiveDirector->value);
    }

    /**
     * Staff must have confirmed TOTP two-factor authentication (Spec 8).
     * The requirement can be switched off only in the local environment.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->isStaff()
            && (config('portal.require_staff_two_factor') || ! app()->isLocal());
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * @return HasOne<MemberProfile, $this>
     */
    public function memberProfile(): HasOne
    {
        return $this->hasOne(MemberProfile::class);
    }

    /**
     * @return HasMany<LicenseDocument, $this>
     */
    public function licenseDocuments(): HasMany
    {
        return $this->hasMany(LicenseDocument::class);
    }

    /**
     * @return HasMany<TaxDocument, $this>
     */
    public function taxDocuments(): HasMany
    {
        return $this->hasMany(TaxDocument::class);
    }

    /**
     * @return HasMany<NotificationSetting, $this>
     */
    public function notificationSettings(): HasMany
    {
        return $this->hasMany(NotificationSetting::class);
    }

    /**
     * @return HasMany<ReminderLog, $this>
     */
    public function reminderLogs(): HasMany
    {
        return $this->hasMany(ReminderLog::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
