<?php

namespace App\Models;

use App\Enums\AgencyRole;
use App\Enums\AgencyStatus;
use App\Enums\InviteStatus;
use App\Enums\LicenseStatus;
use App\Enums\LicenseVerification;
use App\Enums\MemberStatus;
use App\Enums\MemberType;
use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Models\Concerns\HasBlindIndexes;
use App\Observers\MemberProfileObserver;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\MemberProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

#[Fillable([
    'first_name', 'last_name', 'phone', 'address', 'firm_name', 'npn',
    'license_number', 'license_state', 'license_type', 'license_expires_at',
    'agency_id', 'agency_role', 'person_type', 'invite_status', 'status', 'license_verification',
])]
#[ObservedBy(MemberProfileObserver::class)]
class MemberProfile extends Model
{
    /** @use HasFactory<MemberProfileFactory> */
    use HasBlindIndexes, HasFactory;

    /**
     * Encrypted identifiers that can still be looked up by exact value (KEENEY rule).
     */
    protected const BLIND_INDEXES = [
        'member_number' => 'member_number_index',
        'npn' => 'npn_index',
        'certificate_code' => 'certificate_code_index',
    ];

    public const LICENSE_TYPES = [
        'Life & Health',
        'Property & Casualty',
        'Life Only',
        'Accident & Health',
        'Personal Lines',
    ];

    /**
     * What properx.org's application form offers ("License Type(s) Held"). A member may hold several:
     * license_type keeps them comma-separated, as the intake stores them.
     */
    public const LICENSE_TYPE_CHOICES = ['Life', 'Health', 'Property & Casualty', 'Annuity', 'Variable'];

    /**
     * What staff verified: a member changing one of these sends the license back to review.
     */
    public const LICENSE_REVIEW_FIELDS = ['npn', 'license_state', 'license_number', 'license_expires_at'];

    /**
     * NPN format of agency principals and agency people (Agency spec 5.3, the client's answer):
     * 7 to 10 digits. Individual members keep properx.org's 1 to 10 until the client decides.
     */
    public const AGENCY_NPN_PATTERN = '/^\d{7,10}$/';

    public const AGENCY_NPN_RULE = 'regex:'.self::AGENCY_NPN_PATTERN;

    public const AGENCY_NPN_MESSAGE = 'The NPN must be 7 to 10 digits, numbers only.';

    public const INDIVIDUAL_NPN_PATTERN = '/^\d{1,10}$/';

    public const INDIVIDUAL_NPN_MESSAGE = 'The NPN must be up to 10 digits, numbers only.';

    /**
     * The NPN format this person's NPN must follow: agency people 7 to 10 digits, individuals 1 to 10.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function npnFormatRule(bool $inAgency): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($inAgency) {
            if (preg_match($inAgency ? self::AGENCY_NPN_PATTERN : self::INDIVIDUAL_NPN_PATTERN, (string) $value) !== 1) {
                $fail($inAgency ? self::AGENCY_NPN_MESSAGE : self::INDIVIDUAL_NPN_MESSAGE);
            }
        };
    }

    protected static function booted(): void
    {
        // Model events are muted while seeding: factories set these values themselves.
        static::saving(function (self $profile) {
            if (! $profile->exists) {
                $profile->certificate_code ??= static::generateCertificateCode();
                $profile->member_number ??= static::formatMemberNumber($profile->user_id);
            }
        });

        static::saving(function (self $profile) {
            $profile->syncMembershipType();
        });

        // Headcount follows the roster (seeders call Agency::syncHeadcount() themselves).
        static::saved(function (self $profile) {
            if ($profile->wasRecentlyCreated || $profile->wasChanged('agency_id')) {
                Agency::query()
                    ->whereKey(array_filter([$profile->agency_id, $profile->wasRecentlyCreated ? null : $profile->getOriginal('agency_id')]))
                    ->get()
                    ->each->syncHeadcount();
            }
        });

        static::deleted(function (self $profile) {
            $profile->agency?->syncHeadcount();
        });

        // Employees hold no license (Agency spec 3.5): no license status. Runs after
        // syncMembershipType(), which defaults the person type of agency members.
        static::saving(function (self $profile) {
            if ($profile->isAgencyEmployee()) {
                $profile->license_status = null;
            } elseif ($profile->isDirty(['license_expires_at', 'person_type']) || $profile->license_status === null) {
                $profile->license_status = LicenseStatus::fromExpiryDate($profile->license_expires_at);
            }

            // Licensed people wait for staff's verification; employees hold no license.
            if ($profile->isAgencyEmployee()) {
                $profile->license_verification = null;
            } else {
                $profile->license_verification ??= LicenseVerification::PendingReview;

                // Whichever door the member came through (member area, Nova). Staff decide for themselves.
                if ($profile->exists && $profile->isDirty(self::LICENSE_REVIEW_FIELDS) && self::actorIsMember()) {
                    $profile->license_verification = LicenseVerification::PendingReview;
                }
            }

            // The queue's date; staff's reason stays only while the license needs attention.
            if ($profile->isDirty('license_verification')) {
                $profile->license_verification_changed_at = $profile->license_verification === null ? null : now();
            }

            if ($profile->license_verification !== LicenseVerification::NeedsAttention) {
                $profile->license_verification_note = null;
            }
        });
    }

    /**
     * @return list<string>
     */
    public function licenseTypeList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->license_type))));
    }

    /**
     * The site's choices plus whatever this member already holds, so an older value is never lost.
     *
     * @return list<string>
     */
    public function licenseTypeOptions(): array
    {
        return array_values(array_unique([...self::LICENSE_TYPE_CHOICES, ...$this->licenseTypeList()]));
    }

    /**
     * The stored form of the chosen types. The same set keeps its stored spelling, so saving
     * without a change changes nothing.
     *
     * @param  array<int, string>  $types
     */
    public function licenseTypeValue(array $types): string
    {
        $types = array_values(array_unique($types));
        $current = $this->licenseTypeList();

        return array_diff($types, $current) === [] && array_diff($current, $types) === []
            ? (string) $this->license_type
            : implode(', ', $types);
    }

    private static function actorIsMember(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isMember() && ! $actor->isStaff();
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payment_status' => 'unknown',
        'member_type' => 'individual',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'license_status' => LicenseStatus::class,
            // Designated fields, encrypted at rest; looked up through their blind indexes.
            'member_number' => 'encrypted',
            'npn' => 'encrypted',
            'certificate_code' => 'encrypted',
            'payment_status' => PaymentStatus::class,
            'payment_status_updated_at' => 'datetime',
            'payment_event_at' => 'datetime',
            'paid_through_at' => 'datetime',
            'license_verification' => LicenseVerification::class,
            'license_verification_changed_at' => 'datetime',
            // Removal from the agency at its next renewal (G4); access stays until then.
            'removal_effective_at' => 'datetime',
            'removal_scheduled_at' => 'datetime',
            // Staff's words for the person (may name what is wrong with their NPN or state).
            'license_verification_note' => 'encrypted',
            // Contact details of members and agency principals.
            'phone' => 'encrypted',
            'address' => 'encrypted',
            'member_type' => MemberType::class,
            'agency_role' => AgencyRole::class,
            'person_type' => PersonType::class,
            'invite_status' => InviteStatus::class,
            'status' => MemberStatus::class,
        ];
    }

    /**
     * End of the grace period for a past-due membership (Spec 7), null otherwise.
     */
    public function paymentGraceEndsAt(): ?CarbonInterface
    {
        if ($this->payment_status !== PaymentStatus::PastDue || $this->payment_status_updated_at === null) {
            return null;
        }

        return $this->payment_status_updated_at->copy()->addDays(config('portal.payments.grace_days'));
    }

    /**
     * Canceled, or past due beyond the grace period: certificate, library and license
     * documents are closed; the profile, notification settings and tax documents stay open.
     */
    public function hasRestrictedMembership(): bool
    {
        // Agency people follow their agency (Agency spec 6): inactive closes everything that a
        // restricted membership closes; past due keeps access. Staff can also set one person inactive.
        if ($this->agency_id !== null) {
            return $this->agency?->status === AgencyStatus::Inactive || $this->status === MemberStatus::Inactive;
        }

        return match ($this->payment_status) {
            PaymentStatus::Canceled => true,
            // Not "isPast": with no grace period (0 days) access closes the moment it is past due.
            PaymentStatus::PastDue => $this->paymentGraceEndsAt() !== null && ! $this->paymentGraceEndsAt()->isFuture(),
            default => false,
        };
    }

    /**
     * Where a failed payment has got to (H2, Nova's "Dunning"): in the grace period until a date,
     * expired, or null while paid. Agency people follow their agency.
     *
     * @return array{state: 'grace'|'expired', until: ?CarbonInterface}|null
     */
    public function dunning(): ?array
    {
        if ($this->agency_id !== null) {
            return match ($this->agency?->status) {
                AgencyStatus::PastDue => ['state' => 'grace', 'until' => $this->agency->graceEndsAt()],
                AgencyStatus::Inactive => ['state' => 'expired', 'until' => null],
                default => null,
            };
        }

        return match (true) {
            $this->payment_status === PaymentStatus::Canceled, $this->payment_status === PaymentStatus::PastDue && $this->hasRestrictedMembership() => ['state' => 'expired', 'until' => null],
            $this->payment_status === PaymentStatus::PastDue => ['state' => 'grace', 'until' => $this->paymentGraceEndsAt()],
            default => null,
        };
    }

    public function isInPaymentGracePeriod(): bool
    {
        if ($this->agency_id !== null) {
            return $this->agency?->status === AgencyStatus::PastDue && ! $this->hasRestrictedMembership();
        }

        return $this->payment_status === PaymentStatus::PastDue && ! $this->hasRestrictedMembership();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<AgencyInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(AgencyInvitation::class);
    }

    /**
     * On an agency, with a removal scheduled for its next renewal.
     */
    public function leavesAgency(): bool
    {
        return $this->agency_id !== null && $this->removal_effective_at !== null;
    }

    /**
     * Why staff may not delete this profile or its account, or null when they may: agency people
     * and members with a paid subscription are deactivated, never deleted.
     */
    public function deletionBlocker(): ?string
    {
        return match (true) {
            $this->agency_id !== null => 'This person belongs to an agency. Deactivate them instead.',
            filled($this->stripe_subscription_id) || in_array($this->payment_status, [PaymentStatus::Active, PaymentStatus::PastDue], true) => 'This member has a paid membership. Deactivate them instead.',
            default => null,
        };
    }

    /**
     * The license documents this person uploaded (they belong to the user account).
     *
     * @return HasMany<LicenseDocument, $this>
     */
    public function licenseDocuments(): HasMany
    {
        return $this->hasMany(LicenseDocument::class, 'user_id', 'user_id');
    }

    /**
     * The principal of this person's agency by the agency's own record (agencies.principal_user_id).
     */
    public function isPrincipalOfAgency(): bool
    {
        return $this->agency_id !== null && $this->agency?->principal_user_id === $this->user_id;
    }

    /**
     * An agency person who set their password through an invitation but has not yet checked their
     * profile and given the consents (App\Http\Middleware\RequireAcceptedInvitation).
     */
    public function mustFinishInvitation(): bool
    {
        return $this->agency_id !== null
            && $this->invite_status !== InviteStatus::AccountActive
            && $this->invitations()->whereNotNull('used_at')->exists();
    }

    public function isAgencyEmployee(): bool
    {
        return $this->person_type === PersonType::AgencyEmployee;
    }

    /**
     * People who hold a license: everyone except agency employees (individual members have no person type).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function licensed(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull($query->qualifyColumn('person_type'))
            ->orWhere($query->qualifyColumn('person_type'), '!=', PersonType::AgencyEmployee->value));
    }

    public function isAgencyPrincipal(): bool
    {
        return $this->agency_id !== null && $this->agency_role === AgencyRole::Principal;
    }

    /**
     * Member type follows the agency link; agency members default to member / licensed producer.
     * A principal cannot leave their agency until staff reassign the principal role.
     */
    private function syncMembershipType(): void
    {
        if ($this->exists && $this->isDirty('agency_id') && $this->getOriginal('agency_role') === AgencyRole::Principal) {
            throw new LogicException('Reassign the agency principal before moving this person out of the agency.');
        }

        if ($this->agency_id === null) {
            $this->member_type = MemberType::Individual;
            $this->agency_role = null;

            return;
        }

        $this->member_type = MemberType::AgencyMember;
        $this->agency_role ??= AgencyRole::Member;
        $this->person_type ??= PersonType::LicensedProducer;
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public static function generateCertificateCode(): string
    {
        do {
            $code = 'PPX-'.strtoupper(Str::random(10));
        } while (static::query()->whereBlindIndex('certificate_code', $code)->exists());

        return $code;
    }

    /**
     * Member ID printed on the certificate, e.g. "PPX-M000014".
     */
    public static function formatMemberNumber(?int $userId): ?string
    {
        return $userId === null ? null : sprintf('PPX-M%06d', $userId);
    }
}
