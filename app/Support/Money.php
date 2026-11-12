<?php

namespace App\Support;

/**
 * US dollar amounts kept in cents.
 */
final class Money
{
    /**
     * "$1,788" for whole dollars, "$18.44" otherwise.
     */
    public static function format(int $cents): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;

        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, $decimals);
    }

    /**
     * Always with cents, as on invoices: "$199.00".
     */
    public static function exact(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);
    }
}
