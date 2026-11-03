<?php

namespace App\Services\Intake;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\IntakeSubmission;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\MemberInvited;
use App\Support\UsStates;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a paid individual application from the marketing site into a member. Each source + entry
 * is processed once; a repeat gets the stored result. An existing account is linked: only blank
 * fields are filled, anything that differs stays as it is and its field name goes to the audit
 * log. paid_through_at and payment_status stay with the Stripe webhook.
 */
class IndividualIntake
{
    public const SOURCE = 'properx.org';

    /** Profile fields filled from the application, compared when the member already exists. */
    private const PROFILE_FIELDS = ['first_name', 'last_name', 'phone', 'address', 'firm_name', 'npn', 'license_state', 'license_type', 'license_expires_at'];

    /**
     * @param  array<string, mixed>  $data  Validated by IntakeController
     * @return array{outcome: string, member_profile_id: int|null, conflicts: list<string>}
     */
    public function handle(array $data, User $serviceAccount): array
    {
        $entryId = (string) $data['entry_id'];

        if ($existing = $this->submission($entryId)) {
            return $existing->result;
        }

        try {
            $invite = null;

            $result = DB::transaction(function () use ($data, $entryId, $serviceAccount, &$invite) {
                // Claim the entry first: a concurrent delivery hits the unique key and stops here.
                $submission = IntakeSubmission::create(['source' => self::SOURCE, 'entry_id' => $entryId, 'outcome' => 'processing']);

                [$result, $invite] = $this->apply($data, $entryId, $serviceAccount);

                $submission->update(['member_profile_id' => $result['member_profile_id'], 'outcome' => $result['outcome'], 'result' => $result]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->submission($entryId)->result;
        }

        if ($invite !== null && config('portal.intake.send_invite')) {
            $invite->notify(new MemberInvited);
        }

        return $result;
    }

    private function submission(string $entryId): ?IntakeSubmission
    {
        return IntakeSubmission::query()->where('source', self::SOURCE)->where('entry_id', $entryId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array{outcome: string, member_profile_id: int|null, conflicts: list<string>}, 1: User|null}
     */
    private function apply(array $data, string $entryId, User $serviceAccount): array
    {
        $email = Str::lower(trim($data['email']));
        $values = $this->profileValues($data);
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null) {
            $user = User::create([
                'name' => trim($values['first_name'].' '.$values['last_name']),
                'email' => $email,
                // Nobody knows it: the member sets one through the invite or "Forgot your password?".
                'password' => Str::password(40),
            ]);
            $user->assignRole(Role::Member->value);

            return [$this->createProfile($user, $values, $data, $entryId, $serviceAccount, 'created'), $user];
        }

        // Staff and the service account never become members through a form.
        if (! $user->isMember()) {
            return [$this->conflict(null, $entryId, $serviceAccount, ['account_type']), null];
        }

        $profile = $user->memberProfile;

        if ($profile === null) {
            return [$this->createProfile($user, $values, $data, $entryId, $serviceAccount, 'linked'), null];
        }

        // An agency's roster and Stripe customer are managed on the agency: a personal application
        // must not change them (the Stripe webhook looks customers up the same way).
        if ($profile->agency_id !== null) {
            return [$this->conflict($profile, $entryId, $serviceAccount, ['agency_member']), null];
        }

        return [$this->linkProfile($profile, $values, $data, $entryId, $serviceAccount), null];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $data
     * @return array{outcome: string, member_profile_id: int|null, conflicts: list<string>}
     */
    private function createProfile(User $user, array $values, array $data, string $entryId, User $serviceAccount, string $outcome): array
    {
        $conflicts = [];

        if ($this->npnTaken($values['npn'] ?? null, null)) {
            unset($values['npn']);
            $conflicts[] = 'npn';
        }

        $profile = new MemberProfile($values);
        $profile->user()->associate($user);
        $conflicts = array_merge($conflicts, $this->fillStripeIds($profile, $data));
        $profile->save();

        AuditLog::record('member_intake_'.$outcome, $profile, $serviceAccount, array_filter([
            'source' => self::SOURCE,
            'entry_id' => $entryId,
            'conflicts' => $conflicts,
        ]));

        return ['outcome' => $outcome, 'member_profile_id' => $profile->getKey(), 'conflicts' => $conflicts];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $data
     * @return array{outcome: string, member_profile_id: int|null, conflicts: list<string>}
     */
    private function linkProfile(MemberProfile $profile, array $values, array $data, string $entryId, User $serviceAccount): array
    {
        $filled = [];
        $conflicts = [];

        foreach (self::PROFILE_FIELDS as $field) {
            $incoming = $values[$field] ?? null;

            if (blank($incoming)) {
                continue;
            }

            $current = $profile->{$field};

            if (blank($current)) {
                if ($field === 'npn' && $this->npnTaken($incoming, $profile)) {
                    $conflicts[] = $field;

                    continue;
                }

                $profile->{$field} = $incoming;
                $filled[] = $field;
            } elseif ($this->differs($field, $current, $incoming)) {
                $conflicts[] = $field;
            }
        }

        $conflicts = array_merge($conflicts, $this->fillStripeIds($profile, $data));
        $profile->save();

        AuditLog::record('member_intake_linked', $profile, $serviceAccount, array_filter([
            'source' => self::SOURCE,
            'entry_id' => $entryId,
            'filled' => $filled,
            'conflicts' => $conflicts,
        ]));

        return ['outcome' => 'linked', 'member_profile_id' => $profile->getKey(), 'conflicts' => $conflicts];
    }

    /**
     * @param  list<string>  $conflicts
     * @return array{outcome: string, member_profile_id: int|null, conflicts: list<string>}
     */
    private function conflict(?MemberProfile $profile, string $entryId, User $serviceAccount, array $conflicts): array
    {
        AuditLog::record('member_intake_conflict', $profile, $serviceAccount, [
            'source' => self::SOURCE,
            'entry_id' => $entryId,
            'conflicts' => $conflicts,
        ]);

        return ['outcome' => 'conflict', 'member_profile_id' => $profile?->getKey(), 'conflicts' => $conflicts];
    }

    /**
     * Stores the Stripe ids for matching webhooks, unless they would contradict what is on file.
     *
     * @param  array<string, mixed>  $data
     * @return list<string> Names of the fields left as they were.
     */
    private function fillStripeIds(MemberProfile $profile, array $data): array
    {
        $conflicts = [];
        $customer = $data['stripe_customer_id'] ?? null;
        $subscription = $data['stripe_subscription_id'] ?? null;

        if (filled($customer)) {
            $takenElsewhere = MemberProfile::query()
                ->where('stripe_customer_id', $customer)
                ->when($profile->exists, fn ($query) => $query->whereKeyNot($profile->getKey()))
                ->exists();

            if ($takenElsewhere || (filled($profile->stripe_customer_id) && $profile->stripe_customer_id !== $customer)) {
                $conflicts[] = 'stripe_customer_id';
            } else {
                $profile->stripe_customer_id = $customer;
            }
        }

        if (filled($subscription)) {
            if (filled($profile->stripe_subscription_id) && $profile->stripe_subscription_id !== $subscription) {
                $conflicts[] = 'stripe_subscription_id';
            } else {
                $profile->stripe_subscription_id = $subscription;
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileValues(array $data): array
    {
        $types = array_filter(array_map(fn ($type) => trim((string) $type), $data['license_types'] ?? []));

        return [
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'firm_name' => $data['firm_name'] ?? null,
            'npn' => $data['npn'],
            'license_state' => self::stateCode($data['license_state'] ?? null),
            'license_type' => $types === [] ? null : Str::limit(implode(', ', $types), 100, ''),
            'license_expires_at' => $data['license_expires_at'],
        ];
    }

    /**
     * The two-letter code for a state's code or full name (the form sends the name).
     */
    public static function stateCode(?string $state): ?string
    {
        $state = trim((string) $state);

        if ($state === '') {
            return null;
        }

        $code = Str::upper($state);

        if (isset(UsStates::ALL[$code])) {
            return $code;
        }

        $byName = array_change_key_case(array_flip(UsStates::ALL), CASE_LOWER);

        return $byName[Str::lower($state)] ?? null;
    }

    private function npnTaken(?string $npn, ?MemberProfile $except): bool
    {
        return filled($npn) && MemberProfile::query()
            ->whereBlindIndex('npn', $npn)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }

    private function differs(string $field, mixed $current, mixed $incoming): bool
    {
        if ($field === 'license_expires_at') {
            return $current->toDateString() !== (string) $incoming;
        }

        if ($field === 'npn' || $field === 'phone') {
            return preg_replace('/\D/', '', (string) $current) !== preg_replace('/\D/', '', (string) $incoming);
        }

        return Str::lower(trim((string) $current)) !== Str::lower(trim((string) $incoming));
    }
}
