<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Uniqueness of an encrypted attribute, checked through its blind index
 * (an SQL "unique" rule cannot compare encrypted values).
 */
final class UniqueBlindIndex implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model  a model using HasBlindIndexes
     */
    public function __construct(
        private string $model,
        private string $attribute,
        private int|string|null $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $exists = $this->model::query()
            ->whereBlindIndex($this->attribute, (string) $value)
            ->when($this->ignoreId !== null, fn ($query) => $query->whereKeyNot($this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('This :attribute is already registered in the portal. Please contact ProPerx.');
        }
    }
}
