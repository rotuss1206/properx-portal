<?php

namespace App\Services\Agencies;

use App\Enums\DeliveryStatus;
use App\Enums\InviteStatus;
use App\Enums\ReminderType;
use App\Models\Agency;
use App\Models\AgencyInvitation;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\AgencyEnrolledStaff;
use App\Notifications\AgencyInvitationEmail;
use App\Notifications\AgencyWelcome;
use App\Notifications\Member\AgencyInvitationNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Nova\Notifications\NovaNotification;
use Laravel\Nova\URL;

/**
 * Invitations of an enrolled agency's people (Agency spec 7, E1-E3).
 *
 * Each email carries a new one-time link (a row of agency_invitations, only the token's hash
 * stored). The row and the email go together in one transaction: a failed send leaves no link
 * behind, and a retried job sends again. The first invitation is sent once per person
 * (SendAgencyInvitation, after the enrollment); staff's "Resend invitation" revokes every open
 * link and starts a new invitation; reminders (day 3, day 7) send a new link of the same
 * invitation that expires with it.
 */
class AgencyInvitations
{
    /**
     * The first invitation after the enrollment; nothing when the person already had one or set
     * up their account. The principal gets the welcome email with the receipt instead.
     *
     * @param  array<string, mixed>|null  $receipt
     */
    public function sendFirst(MemberProfile $profile, ?array $receipt = null): ?AgencyInvitation
    {
        return DB::transaction(function () use ($profile, $receipt) {
            $profile = MemberProfile::query()->lockForUpdate()->find($profile->getKey());

            if ($profile === null || $profile->agency_id === null
                || $profile->invite_status === InviteStatus::AccountActive
                || $profile->invitations()->exists()) {
                return null;
            }

            $principal = $profile->isPrincipalOfAgency();

            return $this->send($profile, null, $principal && $receipt !== null ? $receipt : null, [
                'kind' => 'enrollment',
                'principal' => $principal,
            ]);
        });
    }

    /**
     * Staff's "Resend invitation": earlier links stop working, a new 14-day invitation goes out.
     */
    public function resend(MemberProfile $profile): AgencyInvitation
    {
        return DB::transaction(function () use ($profile) {
            $profile = MemberProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $revoked = $profile->invitations()->open()->update(['revoked_at' => now()]);

            return $this->send($profile, null, null, ['kind' => 'resend', 'revoked' => $revoked]);
        });
    }

    /**
     * A reminder of an open invitation: a new link with the invitation's own expiry. Written to
     * reminder_logs before the email, so it is never sent twice (unique per invitation and day).
     */
    public function remind(AgencyInvitation $invitation, ReminderType $type): AgencyInvitation
    {
        return DB::transaction(function () use ($invitation, $type) {
            $profile = MemberProfile::query()->lockForUpdate()->findOrFail($invitation->member_profile_id);

            $profile->user->reminderLogs()->create([
                'reminder_type' => $type,
                'agency_invitation_id' => $invitation->getKey(),
                'sent_at' => now(),
                'delivery_status' => DeliveryStatus::Sent,
            ]);

            return $this->send($profile, $invitation, null, ['kind' => 'reminder', 'day' => $type->days()]);
        });
    }

    /**
     * @param  array<string, mixed>|null  $receipt
     * @param  array<string, mixed>  $details
     */
    private function send(MemberProfile $profile, ?AgencyInvitation $reminderOf, ?array $receipt, array $details): AgencyInvitation
    {
        $link = new AgencyInvitation([
            'member_profile_id' => $profile->getKey(),
            'reminder_of_id' => $reminderOf?->getKey(),
            'sent_at' => now(),
            'expires_at' => $reminderOf?->expires_at ?? now()->addDays((int) config('portal.invitations.link_days')),
        ]);
        $token = $link->issueToken();
        $link->save();

        if ($profile->invite_status !== InviteStatus::Invited) {
            $profile->invite_status = InviteStatus::Invited;
            $profile->save();
        }

        AuditLog::record('agency_invitation_sent', $profile, details: $details + ['invitation_id' => $link->getKey()]);

        $user = $profile->user;
        $user->notifyNow($receipt !== null
            ? new AgencyWelcome($token, $link->expires_at, $receipt, $profile->agency?->paid_through_at)
            : new AgencyInvitationEmail($token, $link->expires_at, $reminderOf !== null));

        return $link;
    }

    /**
     * The password chosen on the link's page. The link is spent and its siblings revoked; the
     * principal is done at this point (their consents were given at enrollment), others still
     * check their profile and give the consents.
     */
    public function setPassword(AgencyInvitation $link, string $password): User
    {
        return DB::transaction(function () use ($link, $password) {
            $link = AgencyInvitation::query()->lockForUpdate()->findOrFail($link->getKey());

            if (! $link->isOpen()) {
                throw new \LogicException('The invitation link cannot be used.');
            }

            $profile = $link->memberProfile;
            $user = $profile->user;
            $now = now();

            $link->forceFill(['used_at' => $now])->save();
            $profile->invitations()->open()->whereKeyNot($link->getKey())->update(['revoked_at' => $now]);

            $user->forceFill(['password' => $password])->save(); // UserObserver audits password_changed.

            AuditLog::record('agency_invitation_password_set', $profile, $user, ['invitation_id' => $link->getKey()]);

            if ($profile->isPrincipalOfAgency()) {
                $link->forceFill(['accepted_at' => $now])->save();
                $profile->invite_status = InviteStatus::AccountActive;
                $profile->save();
            }

            return $user;
        });
    }

    /**
     * The profile is checked and the consents given: the account is active.
     *
     * @param  list<string>  $consents
     */
    public function accept(MemberProfile $profile, array $consents): void
    {
        DB::transaction(function () use ($profile, $consents) {
            $link = $profile->invitations()->whereNotNull('used_at')->latest('used_at')->lockForUpdate()->firstOrFail();
            $version = (string) config('portal.invitations.consent_version');

            $link->forceFill([
                'accepted_at' => now(),
                'consents' => ['version' => $version, 'keys' => $consents, 'given_at' => now()->toIso8601String()],
            ])->save();

            $profile->invite_status = InviteStatus::AccountActive;
            $profile->save();

            AuditLog::record('agency_invitation_accepted', $profile, details: [
                'invitation_id' => $link->getKey(),
                'consents' => $consents,
                'consents_version' => $version,
            ]);
        });
    }

    /**
     * "Ask for a new invitation" on the page of an expired, used or replaced link: the principal
     * (bell and email) and staff (Nova) hear of it, at most once per link in
     * portal.invitations.renewal_request_hours. False when it was asked already.
     */
    public function requestNew(AgencyInvitation $link): bool
    {
        $profile = $link->memberProfile;

        if ($profile === null || $profile->invite_status === InviteStatus::AccountActive || $link->state() === 'open') {
            return false;
        }

        $recent = $link->renewal_requested_at?->gt(now()->subHours((int) config('portal.invitations.renewal_request_hours')));

        if ($recent) {
            return false;
        }

        $link->forceFill(['renewal_requested_at' => now()])->save();
        AuditLog::record('agency_invitation_requested', $profile, $profile->user, ['invitation_id' => $link->getKey(), 'state' => $link->state()]);

        $principal = $profile->agency?->principal;

        if ($principal !== null && $principal->getKey() !== $profile->user_id) {
            $principal->notify(new AgencyInvitationNotice(AgencyInvitationNotice::REQUESTED, [$profile->getKey()]));
        }

        $this->notifyStaff(
            "A person on agency #{$profile->agency_id} asked for a new invitation (member profile #{$profile->getKey()}). Use \"Resend invitation\" on their profile.",
            '/resources/member-profiles/'.$profile->getKey(),
            'Open the profile',
            'mail',
        );

        return true;
    }

    /**
     * After the enrollment (Agency spec 7): staff hear of the new agency in Nova, and by email
     * when portal.invitations.staff_email is set.
     */
    public function announceEnrollment(Agency $agency): void
    {
        $this->notifyStaff(
            "New agency enrolled (agency #{$agency->getKey()}): {$agency->tier?->label()}, {$agency->headcount} people. Invitations are on their way.",
            '/resources/agencies/'.$agency->getKey(),
            'Open the agency',
            'user-group',
        );

        if (filled(config('portal.invitations.staff_email'))) {
            Notification::route('mail', (string) config('portal.invitations.staff_email'))->notify(new AgencyEnrolledStaff($agency->getKey()));
        }
    }

    private function notifyStaff(string $message, string $path, string $action, string $icon): void
    {
        $staff = User::permission('agencies.view')->get()->reject(fn (User $user) => $user->isMember());

        foreach ($staff as $user) {
            $user->notify(NovaNotification::make()
                ->message($message)
                ->action($action, URL::make($path))
                ->icon($icon)
                ->type('info'));
        }
    }
}
