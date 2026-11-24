<?php

namespace App\Support;

use App\Enums\PersonType;

/**
 * The client's rule for agencies: every licensed producer needs a copy of their license (PDF, JPG
 * or PNG, up to 10 MB) before the agency pays, in the enrollment and in "Add people". The one place
 * the rule lives, so it can be relaxed (AGENCY_REQUIRE_LICENSE_COPY=false).
 */
final class LicenseCopy
{
    public const NEEDED = 'License copy needed';

    public static function required(): bool
    {
        return (bool) config('agency.require_license_copy');
    }

    /**
     * Whether this person (a PeopleForm row, or the principal's answers with type) still needs a copy.
     *
     * @param  array<string, mixed>  $person
     */
    public static function missing(array $person): bool
    {
        return self::required()
            && ($person['type'] ?? PersonType::LicensedProducer->value) === PersonType::LicensedProducer->value
            && trim((string) ($person['license_file'] ?? '')) === '';
    }
}
