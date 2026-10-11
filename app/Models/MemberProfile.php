<?php

namespace App\Models;

use App\Enums\LicenseStatus;
use Database\Factories\MemberProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'first_name', 'last_name', 'phone', 'address', 'firm_name', 'npn',
    'license_number', 'license_state', 'license_type', 'license_expires_at',
])]
class MemberProfile extends Model
{
    /** @use HasFactory<MemberProfileFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $profile) {
            if ($profile->isDirty('license_expires_at') || $profile->license_status === null) {
                $profile->license_status = LicenseStatus::fromExpiryDate($profile->license_expires_at);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'license_expires_at' => 'date',
            'license_status' => LicenseStatus::class,
        ];
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
}
