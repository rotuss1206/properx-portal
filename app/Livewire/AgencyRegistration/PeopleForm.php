<?php

namespace App\Livewire\AgencyRegistration;

use App\Enums\PersonType;
use App\Models\MemberProfile;
use App\Models\User;
use App\Rules\UniqueBlindIndex;
use App\Support\UsStates;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Screen 5 · People (Agency spec 5.4). The principal (screen 4) is person 1 and is not listed
 * here; these rows are everyone else, added one at a time through the "entry" form. Each
 * person's email, and each licensed producer's NPN, must be new to the portal and appear once
 * in the enrollment, the principal's included. Employees have no NPN or license state.
 */
class PeopleForm extends StepForm
{
    /** Headcount the wizard accepts, the principal included; larger agencies contact ProPerX. */
    public const MAX_HEADCOUNT = 500;

    public const ROW = ['type' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'npn' => '', 'license_state' => ''];

    /** @var list<array{type: string, first_name: string, last_name: string, email: string, npn: string, license_state: string}> */
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

    public function minimumMet(): bool
    {
        return $this->headcount() >= (int) config('agency.minimum_headcount');
    }

    public function entryIsEmpty(): bool
    {
        return collect(self::tidy($this->entry))->except('type')->filter()->isEmpty();
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
        $validator = Validator::make(['entry' => self::tidy($row)], $this->personRules('entry', fn () => -1), $this->messages(), $this->validationAttributes());

        return collect($validator->errors()->messages())
            ->mapWithKeys(fn (array $messages, string $key) => [Str::after($key, 'entry.') => $messages[0]])
            ->all();
    }

    public function normalize(): void
    {
        $this->rows = array_values(array_map(self::tidy(...), array_filter($this->rows, 'is_array')));
        $this->entry = self::tidy($this->entry);
    }

    /**
     * Only the known fields, trimmed; emails in lower case; an employee keeps no NPN or state.
     *
     * @return array{type: string, first_name: string, last_name: string, email: string, npn: string, license_state: string}
     */
    public static function tidy(mixed $row): array
    {
        $row = array_intersect_key(is_array($row) ? $row : [], self::ROW) + self::ROW;
        $row = array_map(fn (mixed $value) => is_scalar($value) ? trim((string) $value) : '', $row);
        $row['email'] = Str::lower($row['email']);

        if ($row['type'] === PersonType::AgencyEmployee->value) {
            $row['npn'] = '';
            $row['license_state'] = '';
        }

        return $row;
    }

    /**
     * The whole list, checked on "Continue".
     */
    public function rules(): array
    {
        $minimum = (int) config('agency.minimum_headcount') - 1;

        return [
            'rows' => ['array', 'min:'.$minimum, 'max:'.(self::MAX_HEADCOUNT - 1)],
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
        return $this->personRules('entry', fn () => $this->editing);
    }

    public function messages(): array
    {
        $min = (int) config('agency.minimum_headcount');

        $messages = [
            'rows.min' => "Agency membership needs at least {$min} people. Add someone else or switch to individual membership.",
            'rows.max' => 'This form takes up to '.self::MAX_HEADCOUNT.' people. Please contact ProPerX to enroll a larger agency.',
        ];

        foreach (['rows.*', 'entry'] as $prefix) {
            $messages += [
                "{$prefix}.type.required" => 'Choose licensed producer or agency employee.',
                "{$prefix}.type.enum" => 'Choose licensed producer or agency employee.',
                "{$prefix}.npn.required" => 'Enter the NPN of this licensed producer.',
                "{$prefix}.npn.regex" => MemberProfile::AGENCY_NPN_MESSAGE,
                "{$prefix}.license_state.required" => 'Choose the resident license state.',
                "{$prefix}.license_state.in" => 'Choose the resident license state.',
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
     * @return array<string, mixed>
     */
    private function personRules(string $prefix, Closure $ownIndex): array
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
                    $fail('This email is already registered in the portal. Please contact ProPerX.');
                }
            }],
            "{$prefix}.npn" => [$producer, 'required', MemberProfile::AGENCY_NPN_RULE, function (string $attribute, mixed $value, Closure $fail) use ($ownIndex, $principal) {
                if ((string) $value === trim($principal->npn)) {
                    $fail("This is the principal's NPN. Each person needs their own NPN.");
                } elseif ($this->listedElsewhere('npn', (string) $value, $ownIndex($attribute))) {
                    $fail('This NPN is already on your list.');
                }
            }, new UniqueBlindIndex(MemberProfile::class, 'npn')],
            "{$prefix}.license_state" => [$producer, 'required', Rule::in(array_keys(UsStates::ALL))],
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
