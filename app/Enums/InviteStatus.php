<?php

namespace App\Enums;

/**
 * Agency members are invited after the agency pays and then set up their own account.
 */
enum InviteStatus: string
{
    /** Enrolled and paid for; the invitation has not gone out yet. */
    case Pending = 'pending';
    case Invited = 'invited';
    case AccountActive = 'account_active';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Invitation will be sent',
            self::Invited => 'Invited',
            self::AccountActive => 'Account active',
        };
    }
}
