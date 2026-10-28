<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An agency enrollment in progress (/join/agency). Anonymous until it is submitted:
 * found by the id in the visitor's session, or later by the token of a "continue" link.
 * The answers (agency and principal details) are encrypted at rest.
 */
class AgencyRegistrationDraft extends Model
{
    use MassPrunable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'step' => 1,
        'furthest_step' => 1,
    ];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'step' => 'integer',
            'furthest_step' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * A new, unsaved draft with a fresh token and expiry.
     */
    public static function start(): self
    {
        $draft = new self;
        $draft->data = [];
        $draft->issueToken();
        $draft->extend();

        return $draft;
    }

    /**
     * Replaces the token; only its hash is stored. Returns the plain token (for the continue link).
     */
    public function issueToken(): string
    {
        $token = Str::random(48);
        $this->token_hash = hash('sha256', $token);

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->active()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Every save keeps the draft for another portal.registration.draft_days days.
     */
    public function extend(): void
    {
        $this->expires_at = now()->addDays((int) config('portal.registration.draft_days'));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
