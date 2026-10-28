<?php

namespace App\Livewire\AgencyRegistration;

use App\Models\MemberProfile;
use App\Models\User;
use App\Rules\UniqueBlindIndex;
use App\Rules\UsPhone;
use App\Support\UsStates;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Screen 4 · Agency principal: becomes the agency admin and counts as person 1. The email
 * becomes their login; email and NPN must not exist in the portal yet.
 */
class PrincipalForm extends StepForm
{
    public const TITLES = [
        'owner' => 'Owner',
        'president' => 'President',
        'principal' => 'Principal',
        'managing_partner' => 'Managing Partner',
        'other' => 'Other',
    ];

    public string $first_name = '';

    public string $last_name = '';

    public string $title = '';

    public string $email = '';

    public string $mobile_phone = '';

    public bool $sms_consent = false;

    public string $npn = '';

    public string $license_state = '';

    public bool $authority_confirmed = false;

    public function normalize(): void
    {
        parent::normalize();

        $this->email = Str::lower($this->email);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['required', Rule::in(array_keys(self::TITLES))],
            'email' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                if (User::query()->where('email', $value)->exists()) {
                    $fail('This email is already registered in the portal. Please contact ProPerx.');
                }
            }],
            'mobile_phone' => ['required', new UsPhone],
            'sms_consent' => ['boolean'],
            'npn' => ['required', 'regex:/^\d{7,10}$/', new UniqueBlindIndex(MemberProfile::class, 'npn')],
            'license_state' => ['required', Rule::in(array_keys(UsStates::ALL))],
            'authority_confirmed' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.in' => 'Choose a title.',
            'npn.regex' => 'The NPN must be 7 to 10 digits, numbers only.',
            'license_state.in' => 'Choose a state.',
            'authority_confirmed.accepted' => 'Please confirm that you are authorized to enroll this agency.',
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'mobile_phone' => 'mobile phone',
            'npn' => 'NPN',
            'license_state' => 'resident license state',
        ];
    }
}
