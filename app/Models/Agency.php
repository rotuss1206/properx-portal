<?php

namespace App\Models;

use App\Enums\AgencyRole;
use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\AgencyType;
use App\Enums\RateType;
use App\Enums\Role;
use App\Exceptions\IneligiblePrincipalException;
use App\Models\Concerns\HasBlindIndexes;
use App\Observers\AgencyObserver;
use App\Services\Agencies\AgencyPricingService;
use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agency enrolls two or more people on one annual invoice. Every person is a user
 * with a member profile linked here; the principal is the agency admin and person 1.
 */
#[Fillable([
    'legal_name', 'dba', 'agency_type', 'agency_npn', 'fein', 'legal_address', 'mailing_address',
    'mailing_same_as_legal', 'phone', 'website', 'billing_email', 'referral_source',
    'tier', 'rate_type', 'partner_code_id', 'principal_user_id', 'status',
])]
#[ObservedBy(AgencyObserver::class)]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasBlindIndexes, HasFactory;

    /**
     * Encrypted identifiers that can still be looked up by exact value (duplicate checks, search).
     */
    protected const BLIND_INDEXES = [
        'agency_npn' => 'agency_npn_index',
        'fein' => 'fein_index',
    ];

    /**
     * "How did you hear about us?" (agency enrollment, Screen 3).
     */
    public const REFERRAL_SOURCES = [
        'phone_call' => 'Phone call',
        'mailer' => 'Mailer',
        'partner' => 'MGA/FMO partner',
        'linkedin' => 'LinkedIn',
        'facebook' => 'Facebook',
        'trade_ad' => 'Trade ad',
        'referral' => 'Referral',
        'other' => 'Other',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'headcount' => 0,
        'mailing_same_as_legal' => true,
    ];

    /**
     * NPN and FEIN are numbers: "12-3456789" and "123456789" are the same FEIN.
     */
    protected static function normalizeForBlindIndex(string $attribute, string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    protected static function booted(): void
    {
        static::saving(function (self $agency) {
            if ($agency->mailing_same_as_legal) {
                $agency->mailing_address = null;
            }
        });

        // Tier and rate type are not chosen by hand (Agency spec 3): the tier follows the
        // headcount and the rate type the payment date. Staff override them with Nova actions.
        static::creating(function (self $agency) {
            $agency->tier ??= AgencyTier::forHeadcount(max($agency->headcount, (int) config('agency.minimum_headcount')));
            $agency->rate_type ??= app(AgencyPricingService::class)->rateTypeFor(now());
        });

        // Also enforced here, not only in Nova: a forged request or other code path cannot
        // make an employee or someone from another agency the principal.
        static::saving(function (self $agency) {
            if ($agency->principal_user_id !== null && $agency->isDirty('principal_user_id')) {
                $problem = $agency->principalProblem(User::query()->with('memberProfile')->find($agency->principal_user_id));

                if ($problem !== null) {
                    throw new IneligiblePrincipalException($problem);
                }
            }
        });
    }

    /**
     * Why $user cannot lead this agency, or null if they can: a licensed producer with an NPN
     * (individual members count as licensed) on this agency's roster. An agency without
     * people yet (just created) may take an individual member who is on no agency.
     */
    public function principalProblem(?User $user): ?string
    {
        $profile = $user?->memberProfile;

        if ($user === null || $profile === null || ! $user->hasRole(Role::Member->value)) {
            return 'The principal must be a member with a member profile.';
        }

        if ($profile->isAgencyEmployee() || blank($profile->npn)) {
            return 'The principal must be a licensed producer with an NPN; agency employees cannot lead an agency.';
        }

        if ($profile->agency_id !== null && $profile->agency_id !== $this->getKey()) {
            return 'This person belongs to another agency.';
        }

        if ($profile->agency_id === null && $this->exists && $this->members()->exists()) {
            return "Choose the principal from this agency's people.";
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'agency_type' => AgencyType::class,
            'tier' => AgencyTier::class,
            'rate_type' => RateType::class,
            'status' => AgencyStatus::class,
            'mailing_same_as_legal' => 'boolean',
            'headcount' => 'integer',
            'paid_through_at' => 'datetime',
            // Encrypted at rest; agency NPN and FEIN are looked up through their blind indexes.
            'agency_npn' => 'encrypted',
            'fein' => 'encrypted',
            'legal_address' => 'encrypted',
            'mailing_address' => 'encrypted',
            'phone' => 'encrypted',
            'billing_email' => 'encrypted',
        ];
    }

    /**
     * Everyone on the roster, the principal included.
     *
     * @return HasMany<MemberProfile, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(MemberProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_user_id');
    }

    /**
     * @return BelongsTo<PartnerCode, $this>
     */
    public function partnerCode(): BelongsTo
    {
        return $this->belongsTo(PartnerCode::class);
    }

    public function mailingAddress(): ?string
    {
        return $this->mailing_same_as_legal ? $this->legal_address : $this->mailing_address;
    }

    public function isLedBy(User $user): bool
    {
        return $this->principal_user_id !== null && $this->principal_user_id === $user->getKey();
    }

    /**
     * Headcount follows the roster. Maintained by the system, so it is saved quietly (not audited).
     */
    public function syncHeadcount(): void
    {
        $this->forceFill(['headcount' => $this->members()->count()])->saveQuietly();
    }

    /**
     * Keep the roster and the Agency Principal role in line with principal_user_id: the
     * principal is on the roster as "principal" and holds the role; a replaced principal
     * stays on the roster as a member and loses the role. Runs after every save (observer);
     * seeders call it directly because model events are muted there.
     */
    public function syncPrincipal(?int $previousPrincipalId = null): void
    {
        if ($previousPrincipalId !== null && $previousPrincipalId !== $this->principal_user_id) {
            $previous = User::query()->with('memberProfile')->find($previousPrincipalId);

            if ($previous?->memberProfile?->agency_id === $this->getKey()) {
                $previous->memberProfile->forceFill(['agency_role' => AgencyRole::Member])->save();
            }

            // principal_user_id is unique: a user leads at most one agency.
            if ($previous?->hasRole(Role::AgencyPrincipal->value)) {
                $previous->removeRole(Role::AgencyPrincipal->value);
            }
        }

        $principal = $this->principal()->with('memberProfile')->first();

        if ($principal === null) {
            return;
        }

        $principal->memberProfile?->forceFill([
            'agency_id' => $this->getKey(),
            'agency_role' => AgencyRole::Principal,
        ])->save();

        if (! $principal->hasRole(Role::AgencyPrincipal->value)) {
            $principal->assignRole(Role::AgencyPrincipal->value);
        }
    }
}
