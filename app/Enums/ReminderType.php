<?php

namespace App\Enums;

/**
 * License expiry reminder, valued by days before expiry.
 */
enum ReminderType: int
{
    case Days60 = 60;
    case Days30 = 30;
    case Days7 = 7;
}
