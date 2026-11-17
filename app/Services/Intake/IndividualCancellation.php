<?php

namespace App\Services\Intake;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\IntakeSubmission;
use App\Models\MemberProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An individual subscription canceled on properx.org (Gravity Forms Stripe, reported by the
 * WordPress plugin): the member is marked canceled with the date, keeps access until the end of the
 * paid period (MemberProfile::hasRestrictedMembership), and the 24-month rule starts (RejoinRule).
 * Each entry is processed once; a repeat gets the stored result. Agency people are never touched.
 */
class IndividualCancellation
{
    public const SOURCE = 'properx.org/cancellation';

    /**
     * @param  array<string, mixed>  $data  Validated by IntakeController
     * @return array{outcome: string, member_profile_id: int|null}
     */
    public function handle(array $data, User $serviceAccount): array
    {
        $entryId = (string) $data['entry_id'];

        if ($existing = $this->submission($entryId)) {
            return $existing->result;
        }

        try {
            return DB::transaction(function () use ($data, $entryId, $serviceAccount) {
                $submission = IntakeSubmission::create(['source' => self::SOURCE, 'entry_id' => $entryId, 'outcome' => 'processing']);
                $result = $this->apply($data, $entryId, $serviceAccount);
                $submission->update(['member_profile_id' => $result['member_profile_id'], 'outcome' => $result['outcome'], 'result' => $result]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->submission($entryId)->result;
        }
    }

    private function submission(string $entryId): ?IntakeSubmission
    {
        return IntakeSubmission::query()->where('source', self::SOURCE)->where('entry_id', $entryId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{outcome: string, member_profile_id: int|null}
     */
    private function apply(array $data, string $entryId, User $serviceAccount): array
    {
        $profile = $this->find($data);

        if ($profile === null) {
            return ['outcome' => 'not_found', 'member_profile_id' => null];
        }

        if ($profile->agency_id !== null || ! $profile->user?->isMember()) {
            AuditLog::record('member_intake_conflict', $profile, $serviceAccount, ['source' => self::SOURCE, 'entry_id' => $entryId, 'conflicts' => ['agency_member']]);

            return ['outcome' => 'conflict', 'member_profile_id' => $profile->getKey()];
        }

        $canceledAt = CarbonImmutable::parse($data['canceled_at'])->min(now());
        $changes = ['membership_canceled_at' => $canceledAt];

        if ($profile->payment_status !== PaymentStatus::Canceled) {
            $changes += ['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()];
        }

        $profile->forceFill($changes)->save();

        AuditLog::record('member_membership_canceled', $profile, $serviceAccount, array_filter([
            'source' => 'properx.org',
            'entry_id' => $entryId,
            'canceled_on' => $canceledAt->toDateString(),
            'access_until' => $profile->paid_through_at?->isFuture() ? $profile->paid_through_at->toDateString() : null,
            'eligible_on' => $canceledAt->addMonthsNoOverflow((int) config('portal.rejoin.wait_months'))->toDateString(),
        ]));

        return ['outcome' => 'canceled', 'member_profile_id' => $profile->getKey()];
    }

    /**
     * By the subscription, then the Stripe customer, then the email.
     *
     * @param  array<string, mixed>  $data
     */
    private function find(array $data): ?MemberProfile
    {
        foreach (['stripe_subscription_id', 'stripe_customer_id'] as $column) {
            if (filled($data[$column] ?? null)) {
                $profile = MemberProfile::query()->where($column, $data[$column])->first();

                if ($profile !== null) {
                    return $profile;
                }
            }
        }

        $email = Str::lower(trim((string) $data['email']));

        return User::query()->whereRaw('lower(email) = ?', [$email])->first()?->memberProfile;
    }
}
