<?php

namespace App\Models;

use App\Support\BlindIndex;
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

    /** Characters in the plain token of a "continue" link. */
    public const TOKEN_LENGTH = 48;

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
            'start_token_expires_at' => 'datetime',
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
        $token = Str::random(self::TOKEN_LENGTH);
        $this->token_hash = hash('sha256', $token);

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return static::query()->active()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * A one-time link that opens a draft started on properx.org/apply, valid for
     * portal.registration.handoff.link_minutes. Only its hash is stored. Returns the plain token.
     */
    public function issueStartToken(): string
    {
        $token = Str::random(self::TOKEN_LENGTH);
        $this->start_token_hash = hash('sha256', $token);
        $this->start_token_expires_at = now()->addMinutes((int) config('portal.registration.handoff.link_minutes'));

        return $token;
    }

    public static function findByStartToken(string $token): ?self
    {
        return static::query()->active()
            ->where('start_token_hash', hash('sha256', $token))
            ->where('start_token_expires_at', '>', now())
            ->first();
    }

    public function spendStartToken(): void
    {
        $this->start_token_hash = null;
        $this->start_token_expires_at = null;
    }

    /**
     * Keeps the blind index of the principal's email (screen 4) in step with the answers.
     */
    public function rememberPrincipalEmail(?string $email): void
    {
        $email = Str::lower(trim((string) $email));

        $this->principal_email_index = filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : BlindIndex::for('draft_principal_email', $email);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forPrincipalEmail(Builder $query, string $email): void
    {
        $query->where('principal_email_index', BlindIndex::for('draft_principal_email', Str::lower(trim($email))));
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
