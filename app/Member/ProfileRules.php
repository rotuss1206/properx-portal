<?php

namespace App\Member;

use App\Models\MemberProfile;
use App\Rules\UniqueBlindIndex;
use App\Rules\UsPhone;
use App\Support\UsStates;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation of the profile page's sections, with messages for people. Only the fields a member
 * may change are listed, so anything else in a forged request is never read. A value left as it
 * was is not held to the format: older records must not block an edit of something else.
 */
final class ProfileRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function contact(MemberProfile $profile, array $input): array
    {
        return self::unlessUnchanged($profile, $input, ['phone'], [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', new UsPhone],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function business(): array
    {
        return ['firm_name' => ['nullable', 'string', 'max:255']];
    }

    /**
     * Agency people hold NPN and resident state only (Agency spec 3.5). The principal's NPN stays
     * required anyway through "required": Agency::principalProblem() needs it.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function license(MemberProfile $profile, array $input): array
    {
        $rules = [
            'npn' => ['required', 'string', 'regex:/^\d{7,10}$/', new UniqueBlindIndex(MemberProfile::class, 'npn', $profile->getKey())],
            'license_state' => ['required', Rule::in(array_keys(UsStates::ALL))],
        ];

        if ($profile->agency_id === null) {
            $rules += [
                'license_number' => ['required', 'string', 'max:50'],
                'license_type' => ['required', Rule::in(MemberProfile::LICENSE_TYPES)],
                'license_expires_at' => ['required', 'date_format:Y-m-d'],
            ];
        }

        return self::unlessUnchanged($profile, $input, ['npn', 'license_state', 'license_type'], $rules);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function password(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::default()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'first_name.required' => 'Enter your first name.',
            'last_name.required' => 'Enter your last name.',
            'npn.required' => 'Enter your National Producer Number.',
            'npn.regex' => 'The NPN must be 7 to 10 digits, numbers only.',
            'license_state.required' => 'Choose your resident state.',
            'license_state.in' => 'Choose your resident state from the list.',
            'license_number.required' => 'Enter your license number.',
            'license_type.required' => 'Choose your license type.',
            'license_type.in' => 'Choose your license type from the list.',
            'license_expires_at.required' => 'Enter the date your license expires.',
            'license_expires_at.date_format' => 'Enter the expiration date as a full date, e.g. 09/30/2027.',
            'current_password.required' => 'Enter your current password.',
            'current_password.current_password' => "That isn't your current password.",
            'password.required' => 'Enter a new password.',
            'password.confirmed' => "The two new passwords don't match.",
            'password.different' => 'Choose a password different from your current one.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'phone' => 'phone number',
            'address' => 'mailing address',
            'firm_name' => 'firm name',
            'npn' => 'NPN',
            'license_number' => 'license number',
            'password' => 'new password',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $attributes
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    private static function unlessUnchanged(MemberProfile $profile, array $input, array $attributes, array $rules): array
    {
        foreach ($attributes as $attribute) {
            if (filled($profile->{$attribute}) && ($input[$attribute] ?? null) === $profile->{$attribute}) {
                $rules[$attribute] = ['required', 'string'];
            }
        }

        return $rules;
    }
}
