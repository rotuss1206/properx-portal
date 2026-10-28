<?php

namespace App\Livewire\AgencyRegistration;

use App\Enums\AgencyTier;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Screen 2 · Tier. Only a starting point: the People screen corrects it to the headcount.
 */
class TierForm extends StepForm
{
    public string $tier = '';

    public function rules(): array
    {
        return [
            'tier' => ['required', Rule::enum(AgencyTier::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'tier.required' => 'Choose the tier closest to the size of your agency.',
            'tier.enum' => 'Choose the tier closest to the size of your agency.',
        ];
    }

    /**
     * ?tier= from a marketing-site link: the tier value ("agency_10to49") or its label as a slug ("agency-plus").
     */
    public static function fromQuery(mixed $value): ?AgencyTier
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = Str::lower(trim($value));

        return AgencyTier::tryFrom($value)
            ?? collect(AgencyTier::cases())->first(fn (AgencyTier $tier) => Str::slug($tier->label()) === $value);
    }
}
