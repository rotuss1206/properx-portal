<?php

namespace App\Models;

use App\Observers\PartnerCodeObserver;
use Carbon\CarbonInterface;
use Database\Factories\PartnerCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MGA/FMO partner code. Codes do not stack: a valid code caps the per-person rate at
 * the partner rate when the tier rate is higher.
 */
#[Fillable(['code', 'partner_name', 'active', 'expires_at'])]
#[ObservedBy(PartnerCodeObserver::class)]
class PartnerCode extends Model
{
    /** @use HasFactory<PartnerCodeFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Stored upper-case without surrounding spaces; codes are typed by hand or come from QR links.
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : self::normalize($value));
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * The code if it exists and is valid at $at (default: now), otherwise null.
     */
    public static function findValid(?string $code, ?CarbonInterface $at = null): ?self
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $partnerCode = static::query()->where('code', self::normalize($code))->first();

        return $partnerCode?->isValid($at) ? $partnerCode : null;
    }

    public function isValid(?CarbonInterface $at = null): bool
    {
        return $this->active && ($this->expires_at === null || $this->expires_at->gt($at ?? now()));
    }

    /**
     * @return HasMany<Agency, $this>
     */
    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }
}
