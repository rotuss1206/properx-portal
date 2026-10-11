<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Observers\MemberProfileObserver;
use Carbon\CarbonInterface;
use Database\Factories\MemberProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'first_name', 'last_name', 'phone', 'address', 'firm_name', 'npn',
    'license_number', 'license_state', 'license_type', 'license_expires_at',
])]
#[ObservedBy(MemberProfileObserver::class)]
class MemberProfile extends Model
{
    /** @use HasFactory<MemberProfileFactory> */
    use HasFactory;

    public const LICENSE_TYPES = [
        'Life & Health',
        'Property & Casualty',
        'Life Only',
        'Accident & Health',
        'Personal Lines',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $profile) {
            $profile->certificate_code ??= static::generateCertificateCode();
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
    ];

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'license_status' => LicenseStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_status_updated_at' => 'datetime',
            'payment_event_at' => 'datetime',
        ];
    }

    /**
     * End of the grace period for a past-due membership (Spec §7), null otherwise.
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

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public static function generateCertificateCode(): string
    {
        do {
            $code = 'PPX-'.strtoupper(Str::random(10));
        } while (static::where('certificate_code', $code)->exists());

        return $code;
    }
}
