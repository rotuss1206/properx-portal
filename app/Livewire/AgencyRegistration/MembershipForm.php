<?php

namespace App\Livewire\AgencyRegistration;

use App\Models\PartnerCode;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Screen 1 · Who is joining? "Just me" leaves for the individual application; this
 * wizard only continues for an agency (member type Agency). Optional MGA/FMO code.
 */
class MembershipForm extends StepForm
{
    public const AGENCY = 'agency';

    public string $type = '';

    public string $partner_code = '';

    public function normalize(): void
    {
        parent::normalize();

        $this->partner_code = PartnerCode::normalize($this->partner_code);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([self::AGENCY])],
            'partner_code' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, Closure $fail) {
                if (PartnerCode::findValid((string) $value) === null) {
                    $fail('This partner code is not valid or has expired. Check the code or leave the field empty.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Choose who is joining.',
            'type.in' => 'Choose who is joining.',
        ];
    }

    public function validationAttributes(): array
    {
        return ['partner_code' => 'partner code'];
    }
}
