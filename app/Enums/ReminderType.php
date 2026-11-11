<?php

namespace App\Enums;

/**
 * A reminder in reminder_logs. License expiry reminders are valued by the days before expiry;
 * agency invitation reminders by 100 + the days since the invitation was sent.
 */
enum ReminderType: int
{
    case Days60 = 60;
    case Days30 = 30;
    case Days7 = 7;
    case InvitationDay3 = 103;
    case InvitationDay7 = 107;

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

    /** Days before expiry, or days after the invitation was sent. */
    public function days(): int
    {
        return $this->value % 100;
    }

    public function label(): string
    {
        return $this->value > 100
            ? "Invitation reminder, day {$this->days()}"
            : "{$this->value} days before expiry";
    }
}
