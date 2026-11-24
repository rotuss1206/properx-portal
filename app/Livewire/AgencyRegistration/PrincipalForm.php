<?php

namespace App\Livewire\AgencyRegistration;

use App\Models\MemberProfile;
use App\Models\User;
use App\Rules\OtherLicenseTypeNeedsText;
use App\Rules\UniqueBlindIndex;
use App\Rules\UsPhone;
use App\Services\Memberships\RejoinRule;
use App\Support\LicenseCopy;
use App\Support\LicenseTypes;
use App\Support\UsStates;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Screen 4 · Agency principal: becomes the agency admin and counts as person 1. The email
 * becomes their login; email (and the NPN, when given) must not exist in the portal yet. A licensed
 * producer: license number, resident state, license types ("Other" with its words) and a copy of
 * the license (LicenseCopy); the NPN is optional (the client's answer).
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

    public string $license_number = '';

    /** @var list<string> */
    public array $license_types = [];

    public string $license_type_other = '';

    /** The id of a PendingLicenseFile of this enrollment (never a path), and the file's own name. */
    public string $license_file = '';

    public string $license_file_name = '';

    public bool $authority_confirmed = false;

    public function normalize(): void
    {
        parent::normalize();

        $this->email = Str::lower($this->email);
        $this->license_number = Str::upper($this->license_number);
        $this->license_types = LicenseTypes::normalize($this->license_types);

        if (! in_array(LicenseTypes::OTHER, $this->license_types, true)) {
            $this->license_type_other = '';
        }
    }

    /**
     * The list of license types is restored as a list (StepForm keeps known keys only).
     */
    public function fillFromDraft(array $data): void
    {
        parent::fillFromDraft($data);

        $this->license_types = LicenseTypes::normalize($data['license_types'] ?? []);
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'title' => ['required', Rule::in(array_keys(self::TITLES))],
            'email' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                if (User::query()->where('email', $value)->exists()) {
                    // The 24-month rule: staff are told of a recently canceled member trying again.
                    RejoinRule::refuse(RejoinRule::profileFor((string) $value), 'agency_enrollment');
                    $fail('This email is already registered in the portal. Please contact ProPerX.');
                }
            }],
            'mobile_phone' => ['required', new UsPhone],
            'sms_consent' => ['boolean'],
            'npn' => ['nullable', MemberProfile::AGENCY_NPN_RULE, function (string $attribute, mixed $value, Closure $fail) {
                if (RejoinRule::refuse(RejoinRule::profileFor(null, (string) $value), 'agency_enrollment')) {
                    $fail(RejoinRule::MESSAGE);
                }
            }, new UniqueBlindIndex(MemberProfile::class, 'npn')],
            'license_state' => ['required', Rule::in(array_keys(UsStates::ALL))],
            'license_number' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\.\/]*$/'],
            'license_types' => ['required', 'array', 'min:1', new OtherLicenseTypeNeedsText],
            'license_types.*' => [Rule::in(LicenseTypes::choices())],
            'license_type_other' => ['nullable', 'string', 'max:100'],
            'license_file' => LicenseCopy::required() ? ['required', 'string', 'max:40'] : ['nullable', 'string', 'max:40'],
            'authority_confirmed' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.in' => 'Choose a title.',
            'npn.regex' => MemberProfile::AGENCY_NPN_MESSAGE,
            'license_state.in' => 'Choose a state.',
            'license_number.required' => 'Enter your license number.',
            'license_number.regex' => 'Use letters, digits, spaces, dashes, dots or slashes.',
            'license_types.required' => 'Choose at least one license type.',
            'license_file.required' => 'Upload a copy of your license (PDF, JPG or PNG, up to 10 MB).',
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
            'license_number' => 'license number',
            'license_types' => 'license type',
            'license_type_other' => 'other license type',
            'license_file' => 'license copy',
        ];
    }
}
