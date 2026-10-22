<?php

namespace App\Enums;

/**
 * Agency members are invited after the agency pays and then set up their own account.
 */
enum InviteStatus: string
{
    case Invited = 'invited';
    case AccountActive = 'account_active';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invited',
            self::AccountActive => 'Account active',
        };
    }
}
