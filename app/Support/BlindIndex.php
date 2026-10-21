<?php

namespace App\Support;

use RuntimeException;

/**
 * Blind index for encrypted columns that must be looked up (KEENEY platform rule):
 * an HMAC-SHA256 of the normalised value, keyed by BLIND_INDEX_KEY (a secret separate
 * from APP_KEY), with the field name as domain separator so equal values in different
 * fields do not produce equal indexes.
 */
final class BlindIndex
{
    public static function for(string $field, ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return hash_hmac('sha256', $field.'|'.self::normalize($value), self::key());
    }

    /**
     * Case and whitespace do not matter for lookups ("ppx-abc 123" finds "PPX-ABC123").
     */
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    private static function key(): string
    {
        $key = (string) config('portal.blind_index_key');

        if ($key === '') {
            throw new RuntimeException('BLIND_INDEX_KEY is not configured.');
        }

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }
}
