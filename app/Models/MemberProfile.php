<?php

namespace App\Models;

use App\Enums\AgencyRole;
use App\Enums\InviteStatus;
use App\Enums\LicenseStatus;
use App\Enums\MemberStatus;
use App\Enums\MemberType;
use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Models\Concerns\HasBlindIndexes;
use App\Observers\MemberProfileObserver;
use Carbon\CarbonInterface;
use Database\Factories\MemberProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

#[Fillable([
    'first_name', 'last_name', 'phone', 'address', 'firm_name', 'npn',
    'license_number', 'license_state', 'license_type', 'license_expires_at',
    'agency_id', 'agency_role', 'person_type', 'invite_status', 'status',
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

        static::saving(function (self $profile) {
            if ($profile->isDirty('license_expires_at') || $profile->license_status === null) {
                $profile->license_status = LicenseStatus::fromExpiryDate($profile->license_expires_at);
            }
        });
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
        return match ($this->payment_status) {
            PaymentStatus::Canceled => true,
            PaymentStatus::PastDue => $this->paymentGraceEndsAt()?->isPast() ?? false,
            default => false,
        };
    }

    public function isInPaymentGracePeriod(): bool
    {
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
