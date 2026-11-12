<?php

namespace App\Support;

use App\Services\Agencies\AgencyEnrollment;

/**
 * Agency addresses are stored as one line ("400 Congress Ave, Suite 210, Austin, TX 78701",
 * AgencyEnrollment::address()). The agency area edits them in the enrollment's fields: this reads
 * a stored line back into them. A line written otherwise (staff typed it in Nova) is not guessed
 * at: the fields stay empty and the page shows the stored text.
 */
final class AgencyAddress
{
    public const EMPTY = ['line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'zip' => ''];

    /**
     * @return array{line1: string, line2: string, city: string, state: string, zip: string}|null
     */
    public static function parse(?string $address): ?array
    {
        $address = trim((string) $address);

        if ($address === '' || ! preg_match('/^(?<street>.+),\s*(?<city>[^,]+),\s*(?<state>[A-Z]{2})\s+(?<zip>\d{5}(?:-\d{4})?)$/', $address, $match)
            || ! isset(UsStates::ALL[$match['state']])) {
            return null;
        }

        $parts = array_map('trim', explode(',', $match['street'], 2));

        $parsed = [
            'line1' => $parts[0],
            'line2' => $parts[1] ?? '',
            'city' => trim($match['city']),
            'state' => $match['state'],
            'zip' => $match['zip'],
        ];

        // Only a line that reads back exactly as it was written counts.
        return self::format($parsed) === $address ? $parsed : null;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    public static function format(array $address): string
    {
        return AgencyEnrollment::address(array_map(fn ($value) => trim((string) $value), $address));
    }
}
