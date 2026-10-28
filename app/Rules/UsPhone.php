<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A US phone number: ten digits, any formatting, optional leading country code 1.
 */
final class UsPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = is_string($value) ? (string) preg_replace('/\D/', '', $value) : '';

        if (! preg_match('/^1?[2-9]\d{9}$/', $digits) || preg_match('/[^\d\s().+-]/', (string) $value)) {
            $fail('Enter the :attribute as a 10-digit US number, e.g. (512) 555-0142.');
        }
    }
}
