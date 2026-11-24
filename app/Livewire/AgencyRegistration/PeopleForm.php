<?php

namespace App\Livewire\AgencyRegistration;

use App\Enums\PersonType;
use App\Models\MemberProfile;
use App\Models\User;
use App\Rules\OtherLicenseTypeNeedsText;
use App\Rules\UniqueBlindIndex;
use App\Services\Memberships\RejoinRule;
use App\Support\LicenseCopy;
use App\Support\LicenseTypes;
use App\Support\UsStates;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Screen 5 · People (Agency spec 5.4). The principal (screen 4) is person 1 and is not listed
 * here; these rows are everyone else, added one at a time through the "entry" form. Each
 * person's email, and each licensed producer's NPN when given, must be new to the portal and
 * appear once in the enrollment, the principal's included.
 *
 * Licensed producers (the client's rules): license number, resident state, license types (with
 * "Other" and its words) and a copy of the license (LicenseCopy: required in the form, and before
 * payment for rows from a spreadsheet); the NPN is optional, 7 to 10 digits when given. Agency
 * employees (W2): name and contact details only.
 */
class PeopleForm extends StepForm
{
    /** Headcount the wizard accepts, the principal included; larger agencies contact ProPerX. */
    public const MAX_HEADCOUNT = 500;

    /** license_file: the id of a PendingLicenseFile of this enrollment or agency, never a path. */
    public const ROW = [
        'type' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'npn' => '', 'license_state' => '',
        'license_number' => '', 'license_types' => [], 'license_type_other' => '', 'license_file' => '', 'license_file_name' => '',
    ];

    /** Fields a licensed producer has and an agency employee does not. */
    public const LICENSE_FIELDS = ['npn', 'license_state', 'license_number', 'license_types', 'license_type_other', 'license_file', 'license_file_name'];

    /** @var list<array{type: string, first_name: string, last_name: string, email: string, npn: string, license_state: string, license_number: string, license_types: list<string>, license_type_other: string, license_file: string, license_file_name: string}> */
    public array $rows = [];

    /** The add / edit form. */
    public array $entry = self::ROW;

    /** The row being edited, or -1 while adding. */
    public int $editing = -1;

    /**
     * Rows of the last CSV upload that could not be added, each with its spreadsheet row and
     * the problem per field; "Fix" moves one into the form, "Skip" drops it.
     *
     * @var list<array{line: int, row: array<string, string>, errors: array<string, string>}>
     */
    public array $problems = [];

    /** The problem being fixed in the form, or -1. */
    public int $fixing = -1;

    public function headcount(): int
    {
        return 1 + count($this->rows);
    }

    /**
     * Only the principal is on the list: the agency still pays for the minimum of members.
     */
    public function principalOnly(): bool
    {
        return $this->rows === [];
    }

    public function entryIsEmpty(): bool
    {
        return collect(self::tidy($this->entry))->except('type')->filter()->isEmpty();
    }

    /**
     * People on the list who still need a license copy (LicenseCopy), as "First Last".
     *
     * @return list<string>
     */
    public function missingLicenseCopies(): array
    {
        return array_values(array_map(
            fn (array $row) => trim($row['first_name'].' '.$row['last_name']),
            array_filter($this->rows, fn (array $row) => LicenseCopy::missing($row)),
        ));
    }

    /**
     * Whether exactly this person is already on the list (an upload repeated, or a row already added).
     *
     * @param  array<string, string>  $row
     */
    public function isListed(array $row): bool
    {
        return in_array(self::tidy($row), $this->rows, true);
    }

    /**
     * The problems of one person by field, with the same rules as the add form; empty when the
     * person can join the list as it is now.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    public function rowErrors(array $row): array
    {
        // A spreadsheet brings no file: its producers get "License copy needed" and upload it on the list.
        $validator = Validator::make(['entry' => self::tidy($row)], $this->personRules('entry', fn () => -1, withFile: false), $this->messages(), $this->validationAttributes());

        return collect($validator->errors()->messages())
            // One message per field: "license_types.0" belongs to "license_types".
            ->mapWithKeys(fn (array $messages, string $key) => [Str::before(Str::after($key, 'entry.'), '.') => $messages[0]])
            ->all();
    }

    public function normalize(): void
    {
        $this->rows = array_values(array_map(self::tidy(...), array_filter($this->rows, 'is_array')));
        $this->entry = self::tidy($this->entry);
    }

    /**
     * Only the known fields, trimmed; emails in lower case; license types from the known choices;
     * an employee keeps no license data at all.
     *
     * @return array{type: string, first_name: string, last_name: string, email: string, npn: string, license_state: string, license_number: string, license_types: list<string>, license_type_other: string, license_file: string, license_file_name: string}
     */
    public static function tidy(mixed $row): array
    {
        $row = array_intersect_key(is_array($row) ? $row : [], self::ROW) + self::ROW;
        $types = LicenseTypes::normalize($row['license_types']);
        $row = array_map(fn (mixed $value) => is_scalar($value) ? trim((string) $value) : '', $row);
        $row['license_types'] = $types;
        $row['email'] = Str::lower($row['email']);
        $row['license_number'] = Str::upper($row['license_number']);

        if (! in_array(LicenseTypes::OTHER, $types, true)) {
            $row['license_type_other'] = '';
        }

        if ($row['type'] === PersonType::AgencyEmployee->value) {
            foreach (self::LICENSE_FIELDS as $field) {
                $row[$field] = self::ROW[$field];
            }
        }

        return $row;
    }

    /**
     * The whole list, checked on "Continue".
     */
    public function rules(): array
    {
        // A principal alone may enroll: the agency pays for the minimum of members (AgencyPricingService::billableSeats).
        return [
            'rows' => ['array', 'max:'.(self::MAX_HEADCOUNT - 1)],
            ...$this->personRules('rows.*', fn (string $attribute) => (int) explode('.', $attribute)[1]),
        ];
    }

    /**
     * The add / edit form, checked against everyone already on the list.
     *
     * @return array<string, mixed>
     */
    public function entryRules(): array
    {
        return $this->personRules('entry', fn () => $this->editing, withFile: true);
    }

    public function messages(): array
    {
        $messages = [
            'rows.max' => 'This form takes up to '.self::MAX_HEADCOUNT.' people. Please contact ProPerX to enroll a larger agency.',
        ];

        foreach (['rows.*', 'entry'] as $prefix) {
            $messages += [
                "{$prefix}.type.required" => 'Choose licensed producer or agency employee.',
                "{$prefix}.type.enum" => 'Choose licensed producer or agency employee.',
                "{$prefix}.npn.regex" => MemberProfile::AGENCY_NPN_MESSAGE,
                "{$prefix}.license_state.required" => 'Choose the resident license state.',
                "{$prefix}.license_state.in" => 'Choose the resident license state.',
                "{$prefix}.license_number.required" => 'Enter the license number of this licensed producer.',
                "{$prefix}.license_number.regex" => 'Use letters, digits, spaces, dashes, dots or slashes.',
                "{$prefix}.license_types.required" => 'Choose at least one license type.',
                "{$prefix}.license_type_other.required" => 'Say which other license type.',
                "{$prefix}.license_file.required" => 'Upload a copy of the license (PDF, JPG or PNG, up to 10 MB).',
            ];
        }

        return $messages;
    }

    public function validationAttributes(): array
    {
        $attributes = [];

        foreach (['rows.*', 'entry'] as $prefix) {
            $attributes += [
                "{$prefix}.first_name" => 'first name',
                "{$prefix}.last_name" => 'last name',
                "{$prefix}.email" => 'email',
                "{$prefix}.npn" => 'NPN',
                "{$prefix}.license_state" => 'resident license state',
                "{$prefix}.license_number" => 'license number',
                "{$prefix}.license_types" => 'license type',
                "{$prefix}.license_type_other" => 'other license type',
                "{$prefix}.license_file" => 'license copy',
            ];
        }

        return $attributes;
    }

    public function toDraft(): array
    {
        return ['rows' => $this->rows, 'entry' => $this->entry, 'editing' => $this->editing, 'problems' => $this->problems, 'fixing' => $this->fixing];
    }

    public function fillFromDraft(array $data): void
    {
        $rows = array_filter(is_array($data['rows'] ?? null) ? $data['rows'] : [], 'is_array');

        $this->rows = array_slice(array_values(array_map(self::tidy(...), $rows)), 0, self::MAX_HEADCOUNT - 1);
        $this->entry = self::tidy($data['entry'] ?? []);
        $editing = $data['editing'] ?? -1;
        $this->editing = is_int($editing) && isset($this->rows[$editing]) ? $editing : -1;

        $problems = [];

        foreach (is_array($data['problems'] ?? null) ? array_slice($data['problems'], 0, self::MAX_HEADCOUNT) : [] as $problem) {
            if (is_array($problem) && is_int($problem['line'] ?? null) && is_array($problem['errors'] ?? null)) {
                $problems[] = [
                    'line' => $problem['line'],
                    'row' => self::tidy($problem['row'] ?? []),
                    // Errors per field (license_types.0 counts as license_types).
                    'errors' => array_filter(array_intersect_key($problem['errors'], self::ROW), 'is_string'),
                ];
            }
        }

        $this->problems = $problems;
        $fixing = $data['fixing'] ?? -1;
        $this->fixing = is_int($fixing) && isset($this->problems[$fixing]) ? $fixing : -1;
    }

    /**
     * @param  Closure(string): int  $ownIndex  the row this attribute belongs to (-1: not on the list yet)
     * @param  bool  $withFile  whether a producer's license copy is checked here (the add form) or only before payment
     * @return array<string, mixed>
     */
    private function personRules(string $prefix, Closure $ownIndex, bool $withFile = false): array
    {
        $producer = 'exclude_unless:'.$prefix.'.type,'.PersonType::LicensedProducer->value;
        $principal = $this->getComponent()->principal;
        // The whole list is checked against the portal in one query; one person by itself, below.
        $registered = $prefix === 'rows.*' ? $this->registeredEmails() : [];

        return [
            "{$prefix}.type" => ['required', Rule::enum(PersonType::class)],
            "{$prefix}.first_name" => ['required', 'string', 'max:100'],
            "{$prefix}.last_name" => ['required', 'string', 'max:100'],
            "{$prefix}.email" => ['required', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($ownIndex, $principal, $registered) {
                $value = Str::lower((string) $value);

                if ($value === Str::lower(trim($principal->email))) {
                    $fail("This is the principal's email. Each person needs their own email.");
                } elseif ($this->listedElsewhere('email', $value, $ownIndex($attribute))) {
                    $fail('This email is already on your list.');
                } elseif (in_array($value, $registered, true) || ($attribute === 'entry.email' && User::query()->where('email', $value)->exists())) {
                    // The 24-month rule: staff are told of a recently canceled member trying again.
                    RejoinRule::refuse(RejoinRule::profileFor($value), 'agency_enrollment');
                    $fail('This email is already registered in the portal. Please contact ProPerX.');
                }
            }],
            // Optional (the client's answer); 7 to 10 digits and new to the portal when given.
            "{$prefix}.npn" => [$producer, 'nullable', MemberProfile::AGENCY_NPN_RULE, function (string $attribute, mixed $value, Closure $fail) use ($ownIndex, $principal) {
                if ((string) $value === trim($principal->npn)) {
                    $fail("This is the principal's NPN. Each person needs their own NPN.");
                } elseif ($this->listedElsewhere('npn', (string) $value, $ownIndex($attribute))) {
                    $fail('This NPN is already on your list.');
                } elseif (RejoinRule::refuse(RejoinRule::profileFor(null, (string) $value), 'agency_enrollment')) {
                    $fail(RejoinRule::MESSAGE);
                }
            }, new UniqueBlindIndex(MemberProfile::class, 'npn')],
            "{$prefix}.license_state" => [$producer, 'required', Rule::in(array_keys(UsStates::ALL))],
            "{$prefix}.license_number" => [$producer, 'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\.\/]*$/'],
            "{$prefix}.license_types" => [$producer, 'required', 'array', 'min:1', new OtherLicenseTypeNeedsText],
            "{$prefix}.license_types.*" => [$producer, Rule::in(LicenseTypes::choices())],
            "{$prefix}.license_type_other" => [$producer, 'nullable', 'string', 'max:100'],
            "{$prefix}.license_file" => $withFile && LicenseCopy::required() ? [$producer, 'required', 'string', 'max:40'] : ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Whether another row than $ownIndex already holds this email or NPN.
     */
    private function listedElsewhere(string $field, string $value, int $ownIndex): bool
    {
        foreach ($this->rows as $index => $row) {
            if ($index !== $ownIndex && $value !== '' && Str::lower(trim((string) ($row[$field] ?? ''))) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The listed emails that already belong to a portal account, in one query.
     *
     * @return list<string>
     */
    private function registeredEmails(): array
    {
        $emails = array_values(array_filter(array_map(fn (array $row) => Str::lower(trim((string) ($row['email'] ?? ''))), $this->rows)));

        return $emails === []
            ? []
            : User::query()->whereIn('email', $emails)->pluck('email')->map(fn (string $email) => Str::lower($email))->all();
    }
}
