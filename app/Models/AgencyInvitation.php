<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One emailed invitation link of an agency person (Agency spec 7). Only the SHA-256 hash of the
 * token is stored; the plain token exists in the email alone. A reminder is a new link of the same
 * invitation (reminder_of_id), expiring with it. See App\Services\Agencies\AgencyInvitations.
 *
 * @property CarbonInterface $sent_at
 * @property CarbonInterface $expires_at
 * @property ?CarbonInterface $used_at
 * @property ?CarbonInterface $accepted_at
 * @property ?CarbonInterface $revoked_at
 * @property ?CarbonInterface $renewal_requested_at
 */
class AgencyInvitation extends Model
{
    public const TOKEN_LENGTH = 48;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'renewal_requested_at' => 'datetime',
            'consents' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MemberProfile, $this>
     */
    public function memberProfile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class);
    }

    /**
     * The invitation this link reminds of.
     *
     * @return BelongsTo<AgencyInvitation, $this>
     */
    public function reminderOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reminder_of_id');
    }

    /**
     * @return HasMany<AgencyInvitation, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(self::class, 'reminder_of_id');
    }

    /**
     * A new random token; its hash goes on this row.
     */
    public function issueToken(): string
    {
        $token = Str::random(self::TOKEN_LENGTH);
        $this->token_hash = self::hash($token);

        return $token;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The link of this token in any state (the page explains an expired or used one).
     */
    public static function findByToken(string $token): ?self
    {
        return strlen($token) === self::TOKEN_LENGTH
            ? static::query()->where('token_hash', self::hash($token))->first()
            : null;
    }

    /**
     * Links that can still be used: not used, not revoked, not expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * open, used, revoked or expired, in that order of precedence after "used".
     */
    public function state(): string
    {
        return match (true) {
            $this->used_at !== null => 'used',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'open',
        };
    }
}
