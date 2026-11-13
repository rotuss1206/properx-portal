<?php

namespace App\Enums;

/**
 * A reminder in reminder_logs (a tiny integer). License expiry reminders are valued by the days
 * before expiry; agency invitation reminders by 100 + the days since the invitation was sent;
 * renewal reminders by 200 + the days before the paid period ends; reminders while a payment is
 * overdue by 240 + the days left of the grace period; 250 and 251 are the "expired" emails.
 * Renewal and grace days come from config (portal.renewals) and must be among these cases.
 */
enum ReminderType: int
{
    case Days60 = 60;
    case Days30 = 30;
    case Days7 = 7;
    case InvitationDay3 = 103;
    case InvitationDay7 = 107;
    case RenewalDays30 = 230;
    case RenewalDays14 = 214;
    case RenewalDays7 = 207;
    case RenewalDays3 = 203;
    case RenewalDays1 = 201;
    case GraceDays7 = 247;
    case GraceDays3 = 243;
    case GraceDays1 = 241;
    case MembershipExpired = 250;
    case AgencyMembershipEnded = 251;

    /**
     * @return list<self>
     */
    public static function licenseExpiry(): array
    {
        return [self::Days60, self::Days30, self::Days7];
    }

    /**
     * @return list<self>
     */
    public static function invitation(): array
    {
        return [self::InvitationDay3, self::InvitationDay7];
    }

    public static function renewal(int $days): ?self
    {
        return $days >= 1 && $days <= 30 ? self::tryFrom(200 + $days) : null;
    }

    public static function grace(int $days): ?self
    {
        return $days >= 1 && $days <= 9 ? self::tryFrom(240 + $days) : null;
    }

    /** Days before expiry or the period's end, days after the invitation, or days of grace left. */
    public function days(): int
    {
        return match (true) {
            $this->value >= 250 => 0,
            $this->value > 240 => $this->value - 240,
            default => $this->value % 100,
        };
    }

    public function label(): string
    {
        return match (true) {
            $this === self::MembershipExpired => 'Membership expired',
            $this === self::AgencyMembershipEnded => "Agency's membership ended",
            $this->value > 240 => "Payment overdue, {$this->days()} ".($this->days() === 1 ? 'day' : 'days').' of grace left',
            $this->value > 200 => "Renewal reminder, {$this->days()} ".($this->days() === 1 ? 'day' : 'days').' before',
            $this->value > 100 => "Invitation reminder, day {$this->days()}",
            default => "{$this->value} days before expiry",
        };
    }
}
