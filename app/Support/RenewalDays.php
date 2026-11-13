<?php

namespace App\Support;

/**
 * The renewal reminder days (portal.renewals.reminder_days) in words, for texts that promise them:
 * "14, 7 and 1 day", "30 days".
 */
final class RenewalDays
{
    public static function phrase(): string
    {
        $days = collect((array) config('portal.renewals.reminder_days'))->map(fn ($day) => (int) $day)->filter(fn (int $day) => $day > 0)->sortDesc()->values();

        if ($days->isEmpty()) {
            return 'before it renews';
        }

        $last = $days->last();
        $list = $days->count() === 1 ? (string) $last : $days->slice(0, -1)->implode(', ').' and '.$last;

        return $list.' '.($last === 1 ? 'day' : 'days');
    }
}
