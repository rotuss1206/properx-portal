<?php

namespace App\Console\Commands;

use App\Enums\InviteStatus;
use App\Enums\ReminderType;
use App\Enums\Role;
use App\Models\AgencyInvitation;
use App\Models\User;
use App\Notifications\Member\AgencyInvitationNotice;
use App\Notifications\ScheduledTaskFailed;
use App\Services\Agencies\AgencyInvitations;
use App\Services\ServiceAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Agency invitation reminders (Agency spec 7): on day 3 and day 7 after an invitation was sent,
 * to people who have not used it; day 7 is copied to the principal (one notice per agency and
 * run). Each reminder is a new link of the same invitation, written to reminder_logs first, so
 * it is never sent twice; a missed day-3 reminder is not sent once day 7 is due. Stops at
 * portal.scheduled_task_budget_seconds; the next run continues.
 */
#[Signature('app:send-invitation-reminders')]
#[Description('Remind agency people who have not accepted their invitation (day 3, day 7)')]
class SendInvitationReminders extends Command
{
    /** @var list<string> */
    private array $errors = [];

    /** @var array<int, array{principal: ?User, profiles: list<int>}> agency id => day-7 people */
    private array $copies = [];

    public function handle(AgencyInvitations $invitations, ServiceAccount $serviceAccounts): int
    {
        // The same instance can run again in one process (tests, schedule:work): start clean.
        $this->errors = [];
        $this->copies = [];
        $deadline = microtime(true) + config('portal.scheduled_task_budget_seconds');
        $outOfTime = false;
        $sent = 0;

        $actor = rescue(fn () => $serviceAccounts->user(), null, false);

        if ($actor !== null) {
            Auth::setUser($actor);
        }

        try {
            AgencyInvitation::query()
                ->whereNull('reminder_of_id')
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->where('sent_at', '<=', now()->subDays(ReminderType::InvitationDay3->days()))
                ->whereHas('memberProfile', fn ($query) => $query->where('invite_status', InviteStatus::Invited)->whereNotNull('agency_id'))
                ->with(['memberProfile.agency.principal'])
                ->chunkById(100, function ($links) use ($invitations, $deadline, &$outOfTime, &$sent) {
                    foreach ($links as $link) {
                        if (microtime(true) >= $deadline) {
                            $outOfTime = true;

                            return false;
                        }

                        $sent += $this->remind($invitations, $link) ? 1 : 0;
                    }
                });
        } catch (Throwable $e) {
            report($e);
            $this->errors[] = 'Job aborted: '.$e::class;
        }

        $this->sendCopies();

        if ($actor !== null) {
            Auth::forgetUser();
        }

        if ($outOfTime) {
            $this->warn('Time budget reached; the next run continues.');
        }

        $this->info(sprintf('Invitation reminders sent: %d, principals copied: %d, errors: %d.', $sent, count($this->copies), count($this->errors)));

        if ($this->errors !== []) {
            $this->alertExecutiveDirector();

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function remind(AgencyInvitations $invitations, AgencyInvitation $link): bool
    {
        $type = self::dueReminder($link);

        if ($type === null) {
            return false;
        }

        $logged = fn (ReminderType $type) => $link->memberProfile->user->reminderLogs()
            ->where('agency_invitation_id', $link->getKey())->where('reminder_type', $type)->exists();

        if ($logged($type) || ($type === ReminderType::InvitationDay3 && $logged(ReminderType::InvitationDay7))) {
            return false;
        }

        try {
            $invitations->remind($link, $type);
        } catch (Throwable $e) {
            report($e);
            $this->errors[] = "Agency invitation #{$link->getKey()}: ".$e::class;

            return false;
        }

        $profile = $link->memberProfile;
        $principal = $profile->agency?->principal;

        if ($type === ReminderType::InvitationDay7 && $principal !== null && $principal->getKey() !== $profile->user_id) {
            $this->copies[$profile->agency_id] ??= ['principal' => $principal, 'profiles' => []];
            $this->copies[$profile->agency_id]['profiles'][] = $profile->getKey();
        }

        return true;
    }

    /**
     * Day 7 once seven whole days have passed, else day 3 once three have.
     */
    public static function dueReminder(AgencyInvitation $link): ?ReminderType
    {
        $days = (int) floor($link->sent_at->diffInDays(now()));

        return match (true) {
            $days >= ReminderType::InvitationDay7->days() => ReminderType::InvitationDay7,
            $days >= ReminderType::InvitationDay3->days() => ReminderType::InvitationDay3,
            default => null,
        };
    }

    private function sendCopies(): void
    {
        foreach ($this->copies as $agencyId => $copy) {
            try {
                $copy['principal']->notify(new AgencyInvitationNotice(AgencyInvitationNotice::NOT_ACCEPTED, $copy['profiles']));
            } catch (Throwable $e) {
                report($e);
                $this->errors[] = "Principal copy for agency #{$agencyId}: ".$e::class;
            }
        }
    }

    private function alertExecutiveDirector(): void
    {
        $directors = User::role(Role::ExecutiveDirector->value)->get();

        if ($directors->isEmpty()) {
            return;
        }

        try {
            Notification::send($directors, new ScheduledTaskFailed($this->getName(), $this->errors));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
