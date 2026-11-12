<?php

namespace App\Member;

use App\Enums\AgencyType;
use App\Models\Agency;
use App\Rules\NotPoBox;
use App\Rules\UniqueBlindIndex;
use App\Rules\UsPhone;
use App\Support\UsStates;
use Illuminate\Validation\Rule;

/**
 * The principal's edit of the agency's details (Agency spec 8.1, G2): the fields of the
 * enrollment's screen 3 with the same rules, except the FEIN, which only staff change
 * (AgencyPolicy::updateFein). Only these fields are read, so nothing else of a forged
 * request is.
 */
final class AgencyDetailsRules
{
    public const FIELDS = ['legal_name', 'dba', 'agency_type', 'agency_npn', 'legal_address', 'mailing_same_as_legal', 'mailing_address', 'phone', 'website', 'billing_email'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, mixed>>
     */
    public static function rules(Agency $agency, array $input): array
    {
        $rules = [
            'legal_name' => ['required', 'string', 'max:255'],
            'dba' => ['nullable', 'string', 'max:255'],
            'agency_type' => ['required', Rule::enum(AgencyType::class)],
            'agency_npn' => ['nullable', 'regex:/^\d{1,10}$/', new UniqueBlindIndex(Agency::class, 'agency_npn', $agency->getKey())],
            ...self::address('legal_address', physical: true),
            'mailing_same_as_legal' => ['boolean'],
            ...(filter_var($input['mailing_same_as_legal'] ?? false, FILTER_VALIDATE_BOOL) ? [] : self::address('mailing_address', physical: false)),
            'phone' => ['required', new UsPhone],
            'website' => ['nullable', 'url:http,https', 'regex:#^https?://[^\s/?.]+(\.[^\s/?.]+)+([/?\#]\S*)?$#i', 'max:255'],
            'billing_email' => ['required', 'email', 'max:255'],
        ];

        // An older value left as it was never blocks the save (e.g. a phone typed by staff).
        foreach (['agency_npn', 'phone'] as $field) {
            if (filled($agency->{$field}) && ($input[$field] ?? null) === $agency->{$field}) {
                $rules[$field] = ['required', 'string'];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function address(string $prefix, bool $physical): array
    {
        $street = $physical ? [new NotPoBox] : [];

        return [
            "{$prefix}.line1" => ['required', 'string', 'max:255', ...$street],
            "{$prefix}.line2" => ['nullable', 'string', 'max:255', ...$street],
            "{$prefix}.city" => ['required', 'string', 'max:100'],
            "{$prefix}.state" => ['required', Rule::in(array_keys(UsStates::ALL))],
            "{$prefix}.zip" => ['required', 'regex:/^\d{5}(-\d{4})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'legal_name.required' => 'Enter the agency’s legal name.',
            'agency_type.required' => 'Choose the agency type.',
            'agency_type.enum' => 'Choose the agency type.',
            'agency_npn.regex' => 'The agency NPN is numbers only, up to 10 digits.',
            'website.regex' => 'Enter a web address such as www.example.com.',
            'website.url' => 'Enter a web address such as www.example.com.',
            'legal_address.zip.regex' => 'Enter a 5-digit ZIP code (or ZIP+4).',
            'mailing_address.zip.regex' => 'Enter a 5-digit ZIP code (or ZIP+4).',
            'legal_address.state.in' => 'Choose a state.',
            'mailing_address.state.in' => 'Choose a state.',
            'legal_address.state.required' => 'Choose a state.',
            'mailing_address.state.required' => 'Choose a state.',
            'billing_email.required' => 'Enter the email for invoices and receipts.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'legal_name' => 'legal agency name',
            'dba' => 'DBA',
            'agency_npn' => 'agency NPN',
            'legal_address.line1' => 'street address',
            'legal_address.line2' => 'address line 2',
            'legal_address.city' => 'city',
            'legal_address.zip' => 'ZIP code',
            'mailing_address.line1' => 'mailing street address',
            'mailing_address.line2' => 'mailing address line 2',
            'mailing_address.city' => 'mailing city',
            'mailing_address.zip' => 'mailing ZIP code',
            'phone' => 'main phone',
            'billing_email' => 'billing email',
        ];
    }
}
