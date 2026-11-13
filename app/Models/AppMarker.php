<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A fact the application records about itself once (key => value), e.g. when membership expiry
 * emails were first switched on. The first writer wins; later calls read it back.
 */
class AppMarker extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function remember(string $key, string $value): string
    {
        $existing = static::query()->find($key);

        if ($existing !== null) {
            return $existing->value;
        }

        try {
            static::query()->create(['key' => $key, 'value' => $value]);
        } catch (UniqueConstraintViolationException) {
            // Someone else wrote it first.
        }

        return static::query()->findOrFail($key)->value;
    }

    public static function read(string $key): ?string
    {
        return static::query()->find($key)?->value;
    }
}
