<?php

namespace App\Services\Agencies;

use App\Enums\AgencyRole;
use App\Enums\AgencyStatus;
use App\Enums\InviteStatus;
use App\Enums\LicenseVerification;
use App\Enums\PersonType;
use App\Enums\Role;
use App\Jobs\SendAgencyInvitation;
use App\Models\Agency;
use App\Models\AgencyRegistrationDraft;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Turns a paid enrollment into the agency, its principal and its people (Agency spec 5.5, 7),
 * from the snapshot taken when the payment started, whatever was edited since. Whoever comes
 * first, the browser returning from the payment or Stripe's webhook, creates it; the other finds
 * it by the subscription id (a lock, and a unique column). Everyone gets the Member role, a
 * password nobody knows and invite_status = invited. The draft goes. Once created, everyone's
 * invitation is queued (the principal's is the welcome email with the receipt) and staff are told;
 * only the call that created the agency does that, so nothing is sent twice.
 */
class AgencyEnrollment
{
    /**
     * @return array{agency: Agency, created: bool}
     */
    public function complete(AgencyRegistrationDraft $draft, string $subscriptionId, string $customerId, ?CarbonInterface $paidThrough, ?CarbonInterface $paidAt = null): array
    {
        return Cache::lock('agency-enrollment:'.$subscriptionId, 30)->block(15, function () use ($draft, $subscriptionId, $customerId, $paidThrough, $paidAt) {
            $existing = Agency::query()->where('stripe_subscription_id', $subscriptionId)->first();

            if ($existing !== null) {
                return ['agency' => $existing, 'created' => false];
            }

            $snapshot = $draft->fresh()?->data['payment']['snapshot'] ?? null;

            if (! is_array($snapshot)) {
                throw new LogicException('The enrollment has no payment snapshot.');
            }

            $agency = DB::transaction(fn () => $this->create($draft, $snapshot, $subscriptionId, $customerId, $paidThrough, $paidAt ?? now()));

            $this->invite($agency, self::receipt($snapshot));

            return ['agency' => $agency, 'created' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function create(AgencyRegistrationDraft $draft, array $snapshot, string $subscriptionId, string $customerId, ?CarbonInterface $paidThrough, CarbonInterface $paidAt): Agency
    {
        $a = $snapshot['agency'];
        $quote = $snapshot['quote'];

        $agency = new Agency;
        $agency->forceFill([
            'legal_name' => $a['legal_name'],
            'dba' => filled($a['dba'] ?? null) ? $a['dba'] : null,
            'agency_type' => $a['agency_type'],
            'agency_npn' => filled($a['agency_npn'] ?? null) ? $a['agency_npn'] : null,
            'fein' => filled($a['fein'] ?? null) ? $a['fein'] : null,
            'legal_address' => self::address($a['legal_address'] ?? []),
            'mailing_same_as_legal' => (bool) ($a['mailing_same_as_legal'] ?? true),
            'mailing_address' => ($a['mailing_same_as_legal'] ?? true) ? null : self::address($a['mailing_address'] ?? []),
            'phone' => $a['phone'],
            'website' => filled($a['website'] ?? null) ? $a['website'] : null,
            'billing_email' => $a['billing_email'],
            'referral_source' => filled($a['referral_source'] ?? null) ? $a['referral_source'] : null,
            'tier' => $quote['tier'],
            'rate_type' => $quote['rate_type'],
            'partner_code_id' => $quote['partner_code_id'] ?? null,
            'stripe_customer_id' => $customerId,
            'stripe_subscription_id' => $subscriptionId,
            'paid_through_at' => $paidThrough,
            'status' => AgencyStatus::Active,
            // The Billing panel from the first payment on, not only from later webhooks: the
            // subscription bills the snapshot's headcount; the payment is the latest event so far
            // (an older event arriving later changes no status, AgencyStripeEvents).
            'billed_headcount' => (int) $quote['headcount'],
            'payment_event_at' => $paidAt,
            'paid_event_at' => $paidAt,
        ])->save();

        $principal = $snapshot['principal'];
        $people = [
            ['type' => PersonType::LicensedProducer->value, 'first_name' => $principal['first_name'], 'last_name' => $principal['last_name'], 'email' => $principal['email'], 'npn' => $principal['npn'], 'license_state' => $principal['license_state'], 'phone' => $principal['mobile_phone'] ?? null, 'principal' => true],
            ...array_map(fn (array $row) => $row + ['phone' => null, 'principal' => false], $snapshot['people']['rows'] ?? []),
        ];

        $principalUser = null;
        $skipped = 0;

        foreach ($people as $person) {
            $user = $this->person($agency, $person);

            if ($user === null) {
                $skipped++;

                continue;
            }

            if ($person['principal']) {
                $principalUser = $user;
            }
        }

        if ($principalUser !== null) {
            $agency->forceFill(['principal_user_id' => $principalUser->getKey()])->save();
        }

        $agency->syncHeadcount();

        AuditLog::record('agency_enrolled', $agency, details: array_filter([
            'draft_id' => $draft->getKey(),
            'tier' => $quote['tier'],
            'rate_type' => $quote['rate_type'],
            'headcount' => $quote['headcount'],
            'partner_rate_applied' => (bool) ($quote['partner_rate_applied'] ?? false),
            'amount' => $quote['subtotal'],
            'surcharge' => $quote['surcharge'],
            'payment_method' => $snapshot['payment_method']['type'] ?? null,
            'consents_version' => $snapshot['consents']['version'] ?? null,
            'consents_given_at' => $snapshot['consents']['given_at'] ?? [],
            'principal_sms_consent' => (bool) ($principal['sms_consent'] ?? false),
            // People whose email or NPN was taken between the review and the payment; staff add them.
            'people_not_created' => $skipped,
        ], fn ($value) => $value !== null && $value !== []));

        $draft->delete();

        return $agency;
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function person(Agency $agency, array $person): ?User
    {
        $email = Str::lower(trim((string) $person['email']));
        $isProducer = $person['type'] === PersonType::LicensedProducer->value;

        if (User::query()->where('email', $email)->exists()
            || ($isProducer && MemberProfile::query()->whereBlindIndex('npn', (string) $person['npn'])->exists())) {
            return null;
        }

        $user = User::create([
            'name' => trim($person['first_name'].' '.$person['last_name']),
            'email' => $email,
            // Nobody knows it: each person sets their own through the invitation.
            'password' => Str::password(40),
        ]);
        $user->assignRole(Role::Member->value);

        $profile = new MemberProfile([
            'first_name' => $person['first_name'],
            'last_name' => $person['last_name'],
            'phone' => $person['phone'],
            'npn' => $isProducer ? $person['npn'] : null,
            'license_state' => $isProducer ? $person['license_state'] : null,
            'agency_id' => $agency->getKey(),
            'agency_role' => $person['principal'] ? AgencyRole::Principal : AgencyRole::Member,
            'person_type' => $person['type'],
            // The invitation is queued as the agency is created (invite()).
            'invite_status' => InviteStatus::Invited,
            'license_verification' => $isProducer ? LicenseVerification::PendingReview : null,
        ]);
        $profile->user()->associate($user);
        $profile->save(); // MemberProfileObserver audits agency_member_added.

        return $user;
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    private function invite(Agency $agency, array $receipt): void
    {
        $agency->members()->pluck('id')->each(fn (int $profileId) => SendAgencyInvitation::dispatch(
            $profileId,
            $agency->principal?->memberProfile?->getKey() === $profileId ? $receipt : null,
        ));

        app(AgencyInvitations::class)->announceEnrollment($agency);
    }

    /**
     * What the principal's welcome email shows: amounts and plan, nothing else.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{subtotal: int, surcharge: int, total: int, tier: string, rate_type: string, headcount: int, partner_rate_applied: bool}
     */
    private static function receipt(array $snapshot): array
    {
        $quote = $snapshot['quote'];

        return [
            'subtotal' => (int) $quote['subtotal'],
            'surcharge' => (int) $quote['surcharge'],
            'total' => (int) $quote['total'],
            'tier' => (string) $quote['tier'],
            'rate_type' => (string) $quote['rate_type'],
            'headcount' => (int) $quote['headcount'],
            'partner_rate_applied' => (bool) ($quote['partner_rate_applied'] ?? false),
        ];
    }

    /**
     * "400 Congress Ave, Suite 210, Austin, TX 78701", as the agencies table keeps addresses.
     *
     * @param  array<string, string>  $address
     */
    public static function address(array $address): string
    {
        $cityLine = trim(($address['city'] ?? '').', '.trim(($address['state'] ?? '').' '.($address['zip'] ?? '')), ', ');

        return implode(', ', array_filter([trim($address['line1'] ?? ''), trim($address['line2'] ?? ''), $cityLine]));
    }
}
