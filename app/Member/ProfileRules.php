<?php

namespace App\Member;

use App\Models\MemberProfile;
use App\Rules\UniqueBlindIndex;
use App\Rules\UsPhone;
use App\Support\UsStates;
use Closure;
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
    public static function business(MemberProfile $profile, array $input): array
    {
        $rules = ['firm_name' => ['nullable', 'string', 'max:255']];

        // Individuals as on properx.org: 1 to 10 digits; agency people 7 to 10. An NPN cannot be
        // cleared (Agency::principalProblem() needs the principal's), but a record without one
        // can still change its firm name.
        if (! $profile->isAgencyEmployee()) {
            $rules['npn'] = [filled($profile->npn) ? 'required' : 'nullable', 'string', MemberProfile::npnFormatRule($profile->agency_id !== null), new UniqueBlindIndex(MemberProfile::class, 'npn', $profile->getKey())];
        }

        return self::unlessUnchanged($profile, $input, ['npn'], $rules);
    }

    /**
     * Agency people hold a resident state only here (Agency spec 3.5). The license number is
     * optional: properx.org's application does not ask for it.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function license(MemberProfile $profile, array $input): array
    {
        $rules = ['license_state' => ['required', Rule::in(array_keys(UsStates::ALL))]];

        if ($profile->agency_id === null) {
            $rules += [
                'license_number' => ['nullable', 'string', 'max:50'],
                'license_type' => ['required', 'array', self::fitsLicenseTypeColumn(...)],
                'license_type.*' => ['string', Rule::in($profile->licenseTypeOptions())],
                'license_expires_at' => ['required', 'date_format:Y-m-d'],
            ];
        }

        return self::unlessUnchanged($profile, $input, ['license_state'], $rules);
    }

    /**
     * The types are stored in one column of 100 characters; nothing may be cut off.
     */
    private static function fitsLicenseTypeColumn(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_array($value) && mb_strlen(implode(', ', array_filter($value, 'is_string'))) > 100) {
            $fail('Please choose fewer license types.');
        }
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
            'license_state.required' => 'Choose your resident state.',
            'license_state.in' => 'Choose your resident state from the list.',
            'license_type.required' => 'Choose at least one license type.',
            'license_type.array' => 'Choose your license types from the list.',
            'license_type.*.string' => 'Choose your license types from the list.',
            'license_type.*.in' => 'Choose your license types from the list.',
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
