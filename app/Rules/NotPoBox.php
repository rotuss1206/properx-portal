<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A street address, not a post office box ("PO Box 12", "P.O. Box", "Post Office Box",
 * "POB 7", "Box 123"). Agency legal addresses must be physical (agency enrollment, Screen 3).
 */
final class NotPoBox implements ValidationRule
{
    private const PATTERN = '/\b(?:p\.?\s*o\.?\s*b(?:ox|\.)?|post\s+office\s+box|pob)\b|^\s*box\s+\d/i';

    public static function matches(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::matches($value)) {
            $fail('The :attribute must be a street address, not a PO box.');
        }
    }
}
