<?php

namespace App\Services\ApiKeys;

use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API key authentication for the API Service account (Spec 3.7, 8).
 *
 * Keys are long random strings, so a plain SHA-256 hash is enough to store them
 * (no slow hashing needed) and lets us look a key up directly by its hash.
 */
class ApiKeyManager
{
    public const PREFIX = 'ppx_';

    /**
     * Issue a new key; the plain key is returned once and never stored.
     *
     * @return array{0: ApiKey, 1: string}
     */
    public function issue(User $user, string $name = 'default'): array
    {
        $plain = self::PREFIX.Str::random(48);

        return [$this->register($user, $plain, $name), $plain];
    }

    /**
     * Store the hash of a key that already exists outside the app (e.g. in Key Vault).
     */
    public function register(User $user, string $plain, string $name = 'default'): ApiKey
    {
        return $user->apiKeys()->firstOrCreate(
            ['key_hash' => self::hash($plain)],
            ['name' => $name, 'last_four' => substr($plain, -4)],
        );
    }

    /**
     * Issue a new key and let the current ones expire after the overlap window,
     * so the new key can be rolled out to Key Vault without downtime. Audited.
     *
     * @return array{0: ApiKey, 1: string}
     */
    public function rotate(User $user, int $overlapHours): array
    {
        return DB::transaction(function () use ($user, $overlapHours) {
            $expiresAt = now()->addHours($overlapHours);

            $user->apiKeys()->usable()
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $expiresAt))
                ->update(['expires_at' => $expiresAt]);

            $issued = $this->issue($user, 'rotated '.now()->toDateString());

            AuditLog::record('api_key_rotated', $user);

            return $issued;
        });
    }

    /**
     * The API Service account owning this key, or null when the key is unknown,
     * revoked, expired or belongs to anyone else.
     */
    public function authenticate(?string $plain): ?User
    {
        if (blank($plain)) {
            return null;
        }

        $key = ApiKey::query()->usable()->with('user')->where('key_hash', self::hash($plain))->first();

        if ($key === null || ! $key->user?->hasRole(Role::ApiService->value)) {
            return null;
        }

        // Avoid a write on every call.
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $key->user;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
