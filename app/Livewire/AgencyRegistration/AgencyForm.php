<?php

namespace App\Livewire\AgencyRegistration;

use App\Enums\AgencyType;
use App\Models\Agency;
use App\Rules\NotPoBox;
use App\Rules\UniqueBlindIndex;
use App\Rules\UsPhone;
use App\Support\UsStates;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Screen 3 · Your agency (fields and required ones per the agency membership spec).
 */
class AgencyForm extends StepForm
{
    public const EMPTY_ADDRESS = ['line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'zip' => ''];

    public string $legal_name = '';

    public string $dba = '';

    public string $agency_type = '';

    public string $agency_npn = '';

    public string $fein = '';

    /** @var array<string, string> */
    public array $legal_address = self::EMPTY_ADDRESS;

    public bool $mailing_same_as_legal = true;

    /** @var array<string, string> */
    public array $mailing_address = self::EMPTY_ADDRESS;

    public string $phone = '';

    public string $website = '';

    public string $billing_email = '';

    public string $referral_source = '';

    public function normalize(): void
    {
        parent::normalize();

        $this->billing_email = Str::lower($this->billing_email);

        // "www.example.com" is accepted as https://www.example.com.
        if ($this->website !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $this->website)) {
            $this->website = 'https://'.$this->website;
        }
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'dba' => ['nullable', 'string', 'max:255'],
            'agency_type' => ['required', Rule::enum(AgencyType::class)],
            'agency_npn' => ['nullable', 'regex:/^\d{7,10}$/', new UniqueBlindIndex(Agency::class, 'agency_npn')],
            'fein' => ['nullable', 'regex:/^\d{2}-?\d{7}$/', new UniqueBlindIndex(Agency::class, 'fein')],
            ...self::addressRules('legal_address', physical: true),
            'mailing_same_as_legal' => ['boolean'],
            ...($this->mailing_same_as_legal ? [] : self::addressRules('mailing_address', physical: false)),
            'phone' => ['required', new UsPhone],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'billing_email' => ['required', 'email', 'max:255'],
            'referral_source' => ['nullable', Rule::in(array_keys(Agency::REFERRAL_SOURCES))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function addressRules(string $prefix, bool $physical): array
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

    public function messages(): array
    {
        return [
            'agency_npn.regex' => 'The agency NPN must be 7 to 10 digits.',
            'fein.regex' => 'Enter the FEIN as 9 digits, e.g. 12-3456789.',
            'legal_address.zip.regex' => 'Enter a 5-digit ZIP code (or ZIP+4).',
            'mailing_address.zip.regex' => 'Enter a 5-digit ZIP code (or ZIP+4).',
            'legal_address.state.in' => 'Choose a state.',
            'mailing_address.state.in' => 'Choose a state.',
            'website.url' => 'Enter a web address such as www.example.com.',
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'legal_name' => 'legal agency name',
            'dba' => 'DBA',
            'agency_type' => 'agency type',
            'agency_npn' => 'agency NPN',
            'fein' => 'FEIN',
            'legal_address.line1' => 'street address',
            'legal_address.line2' => 'address line 2',
            'legal_address.city' => 'city',
            'legal_address.state' => 'state',
            'legal_address.zip' => 'ZIP code',
            'mailing_address.line1' => 'mailing street address',
            'mailing_address.line2' => 'mailing address line 2',
            'mailing_address.city' => 'mailing city',
            'mailing_address.state' => 'mailing state',
            'mailing_address.zip' => 'mailing ZIP code',
            'phone' => 'main phone',
            'website' => 'website',
            'billing_email' => 'billing email',
            'referral_source' => 'answer',
        ];
    }
}
