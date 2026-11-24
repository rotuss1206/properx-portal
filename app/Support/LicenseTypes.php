<?php

namespace App\Support;

use App\Models\MemberProfile;
use Illuminate\Support\Str;

/**
 * License types held, as on properx.org (MemberProfile::LICENSE_TYPE_CHOICES), plus "Other" with
 * the person's own words. A profile keeps them comma-separated in license_type, "Other: <text>" last.
 */
final class LicenseTypes
{
    public const OTHER = 'Other';

    /**
     * @return list<string>
     */
    public static function choices(): array
    {
        return [...MemberProfile::LICENSE_TYPE_CHOICES, self::OTHER];
    }

    /**
     * Only known choices, once each, in the choices' order.
     *
     * @return list<string>
     */
    public static function normalize(mixed $types): array
    {
        $given = array_map(fn (mixed $type) => is_scalar($type) ? trim((string) $type) : '', is_array($types) ? $types : []);

        return array_values(array_filter(self::choices(), fn (string $choice) => in_array($choice, $given, true)));
    }

    /**
     * "Life, Health, Other: Surplus lines" for member_profiles.license_type (at most 100 characters).
     *
     * @param  list<string>  $types
     */
    public static function forProfile(array $types, string $other): ?string
    {
        $types = self::normalize($types);
        $parts = array_values(array_filter($types, fn (string $type) => $type !== self::OTHER));

        if (in_array(self::OTHER, $types, true)) {
            $parts[] = trim($other) === '' ? self::OTHER : self::OTHER.': '.trim($other);
        }

        return $parts === [] ? null : Str::limit(implode(', ', $parts), 100, '');
    }

    /**
     * A spreadsheet cell: "Life, Health", "life; health", "Other: Surplus lines". Unknown words
     * become "Other" with those words.
     *
     * @return array{types: list<string>, other: string}
     */
    public static function fromText(string $text): array
    {
        $types = [];
        $other = [];

        foreach (preg_split('/\s*[,;|\/]\s*/', trim($text)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^other\s*:?\s*(.*)$/i', $part, $match) === 1) {
                $types[] = self::OTHER;
                $other[] = trim($match[1]);

                continue;
            }

            $known = collect(MemberProfile::LICENSE_TYPE_CHOICES)
                ->first(fn (string $choice) => Str::lower($choice) === Str::lower($part)
                    || Str::lower(str_replace('&', 'and', $choice)) === Str::lower(str_replace('&', 'and', $part))
                    || ($choice === 'Property & Casualty' && in_array(Str::lower($part), ['p&c', 'p & c', 'pc'], true)));

            if ($known !== null) {
                $types[] = $known;
            } else {
                $types[] = self::OTHER;
                $other[] = $part;
            }
        }

        return ['types' => self::normalize($types), 'other' => Str::limit(implode(', ', array_filter($other)), 100, '')];
    }
}
