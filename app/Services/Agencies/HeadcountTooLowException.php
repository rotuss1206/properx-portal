<?php

namespace App\Services\Agencies;

use InvalidArgumentException;

class HeadcountTooLowException extends InvalidArgumentException
{
    public static function for(int $headcount, int $minimum): self
    {
        return new self("Agency membership needs at least {$minimum} people; got {$headcount}.");
    }
}
