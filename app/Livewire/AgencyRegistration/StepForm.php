<?php

namespace App\Livewire\AgencyRegistration;

use Illuminate\Support\Facades\Validator;
use Livewire\Form;

/**
 * The answers of one enrollment screen. Saved to the draft as plain arrays and validated
 * before "Continue"; "Back" saves them as they are, so nothing typed is lost.
 */
abstract class StepForm extends Form
{
    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [];
    }

    /**
     * Tidies the input (spaces, case) before it is validated.
     */
    public function normalize(): void
    {
        foreach ($this->all() as $key => $value) {
            if (is_string($value)) {
                $this->{$key} = trim($value);
            } elseif (is_array($value)) {
                $this->{$key} = array_map(fn ($item) => is_string($item) ? trim($item) : $item, $value);
            }
        }
    }

    public function passes(): bool
    {
        return Validator::make($this->all(), $this->rules(), $this->messages(), $this->validationAttributes())->passes();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDraft(): array
    {
        return $this->all();
    }

    /**
     * Restores saved answers, keeping each property's type; unknown or malformed keys are ignored.
     *
     * @param  array<string, mixed>  $data
     */
    public function fillFromDraft(array $data): void
    {
        foreach ($this->all() as $key => $current) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (is_bool($current)) {
                $this->{$key} = (bool) $value;
            } elseif (is_string($current) && is_scalar($value)) {
                $this->{$key} = (string) $value;
            } elseif (is_array($current) && is_array($value)) {
                foreach (array_keys($current) as $field) {
                    if (isset($value[$field]) && is_scalar($value[$field])) {
                        $current[$field] = (string) $value[$field];
                    }
                }

                $this->{$key} = $current;
            }
        }
    }
}
