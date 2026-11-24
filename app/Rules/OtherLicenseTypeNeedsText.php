<?php

namespace App\Rules;

use App\Support\LicenseTypes;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * On a list of license types (…license_types): "Other" needs its own words in the sibling
 * …license_type_other field of the same person.
 */
final class OtherLicenseTypeNeedsText implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ! in_array(LicenseTypes::OTHER, $value, true)) {
            return;
        }

        $other = data_get($this->data, Str::beforeLast($attribute, 'license_types').'license_type_other');

        if (trim((string) $other) === '') {
            $fail('Say which other license type.');
        }
    }
}
