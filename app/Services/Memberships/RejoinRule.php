<?php

namespace App\Services\Memberships;

use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Nova\Notifications\NovaNotification;
use Laravel\Nova\URL;

/**
 * The 24-month rule (client's answer): someone whose individual membership was canceled on
 * properx.org (MemberProfile::membership_canceled_at, reported by the WordPress plugin) may not
 * apply again, as an individual or through an agency, until portal.rejoin.wait_months have passed,
 * unless staff lift the wait (with a reason, audited). A refused attempt is audited and staff are
 * told in Nova, at most once a day per person and place.
 */
class RejoinRule
{
    /** Why staff lift the wait (fixed list: the audit log has no free text). */
    public const LIFT_REASONS = [
        'approved_exception' => 'Approved exception',
        'canceled_in_error' => 'Canceled in error',
        'joining_agency' => 'Joining an agency (approved)',
        'other' => 'Other',
    ];

    /** What an applicant is told (no dates: the form is public). */
    public const MESSAGE = 'This person cannot join right now. Please contact ProPerX.';

    /** Where an attempt was refused. */
    public const CONTEXTS = [
        'intake' => 'an individual application on properx.org',
        'agency_enrollment' => 'an agency enrollment',
        'move_to_agency' => 'Move to an agency',
    ];

    /**
     * The day the person may apply again, or null when nothing holds them back.
     */
    public static function blockedUntil(?MemberProfile $profile): ?CarbonInterface
    {
        $canceled = $profile?->membership_canceled_at;

        if ($canceled === null) {
            return null;
        }

        // Lifted by staff for this cancellation (a later cancellation counts again).
        if ($profile->rejoin_block_lifted_at !== null && $profile->rejoin_block_lifted_at->gte($canceled)) {
            return null;
        }

        $until = $canceled->copy()->addMonthsNoOverflow((int) config('portal.rejoin.wait_months'));

        return $until->isFuture() ? $until : null;
    }

    /**
     * The profile behind an email or an NPN (blind index), whichever finds one first.
     */
    public static function profileFor(?string $email, ?string $npn = null): ?MemberProfile
    {
        $email = Str::lower(trim((string) $email));

        if ($email !== '') {
            $profile = User::query()->whereRaw('lower(email) = ?', [$email])->first()?->memberProfile;

            if ($profile !== null) {
                return $profile;
            }
        }

        return filled($npn) ? MemberProfile::query()->whereBlindIndex('npn', (string) $npn)->first() : null;
    }

    /**
     * Records a refused attempt (audit and a Nova notification to staff who manage members),
     * once a day per person and context. True when the person is held back.
     */
    public static function refuse(?MemberProfile $profile, string $context): bool
    {
        $until = self::blockedUntil($profile);

        if ($until === null) {
            return false;
        }

        if (Cache::add('rejoin-refused:'.$profile->getKey().':'.$context, true, now()->addDay())) {
            AuditLog::record('member_rejoin_blocked', $profile, details: [
                'context' => $context,
                'canceled_on' => $profile->membership_canceled_at->toDateString(),
                'eligible_on' => $until->toDateString(),
            ]);

            $message = 'A member canceled on '.$profile->membership_canceled_at->toFormattedDateString().' tried to join again through '
                .(self::CONTEXTS[$context] ?? $context).' (member profile #'.$profile->getKey().'). Eligible again on '.$until->toFormattedDateString().'.';

            User::permission('members.update')->get()->reject(fn (User $user) => $user->isMember())->each(fn (User $user) => $user->notify(
                NovaNotification::make()
                    ->message($message)
                    ->action('Open the member', URL::make('/resources/member-profiles/'.$profile->getKey()))
                    ->icon('exclamation-circle')
                    ->type('warning')
            ));
        }

        return true;
    }

    /**
     * Staff lift the wait for the current cancellation.
     */
    public static function lift(MemberProfile $profile, string $reason, User $by): bool
    {
        if (! array_key_exists($reason, self::LIFT_REASONS) || self::blockedUntil($profile) === null) {
            return false;
        }

        $profile->forceFill(['rejoin_block_lifted_at' => now(), 'rejoin_block_lifted_reason' => $reason])->save();
        AuditLog::record('member_rejoin_block_lifted', $profile, $by, [
            'reason' => $reason,
            'canceled_on' => $profile->membership_canceled_at->toDateString(),
        ]);

        return true;
    }

    /**
     * What Nova shows: "Eligible again on <date>", "Wait lifted on <date> (<reason>)", or null.
     */
    public static function summary(MemberProfile $profile): ?string
    {
        if ($profile->membership_canceled_at === null) {
            return null;
        }

        if ($until = self::blockedUntil($profile)) {
            return 'Eligible again on '.$until->toFormattedDateString();
        }

        if ($profile->rejoin_block_lifted_at !== null && $profile->rejoin_block_lifted_at->gte($profile->membership_canceled_at)) {
            return 'Wait lifted on '.$profile->rejoin_block_lifted_at->toFormattedDateString().' ('.(self::LIFT_REASONS[$profile->rejoin_block_lifted_reason] ?? $profile->rejoin_block_lifted_reason).')';
        }

        return 'Eligible again';
    }
}
