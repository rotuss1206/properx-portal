<?php

namespace App\Member;

use App\Models\User;

/**
 * Pages of the member area (/member, docs/specs/member-area.md) in menu order, and who
 * sees them. Pages a person may not see answer 404 and are left out of the menu.
 */
final class MemberMenu
{
    /**
     * Route key => [label, icon in public/portal/img/icons.svg].
     */
    public const PAGES = [
        'dashboard' => ['Dashboard', 'dashboard'],
        'profile' => ['Profile', 'profile'],
        'license-documents' => ['License documents', 'license'],
        'tax-documents' => ['Tax documents', 'tax'],
        'certificate' => ['Certificate', 'certificate'],
        'billing' => ['Billing', 'billing'],
        'library' => ['Compliance library', 'library'],
        'notifications' => ['Notifications', 'notifications'],
    ];

    /**
     * @return list<array{key: string, label: string, icon: string, url: string, active: bool}>
     */
    public static function items(User $user, ?string $current): array
    {
        $items = [];

        foreach (self::PAGES as $key => [$label, $icon]) {
            if (self::visible($user, $key)) {
                $items[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'url' => route('member.'.$key), 'active' => $key === $current];
            }
        }

        return $items;
    }

    /**
     * Agency people hold NPN and resident state only (no license document uploads) and the
     * principal pays the agency's invoice, so only the principal sees Billing.
     */
    public static function visible(User $user, string $key): bool
    {
        $profile = $user->memberProfile;
        $inAgency = $profile?->agency_id !== null;

        return match ($key) {
            'license-documents' => ! $inAgency || (bool) config('portal.member_area.agency_people.license_upload'),
            'billing' => ! $inAgency || ($profile->isAgencyPrincipal() && config('portal.member_area.agency_people.principal_billing')),
            default => array_key_exists($key, self::PAGES),
        };
    }

    public static function label(string $key): string
    {
        return self::PAGES[$key][0];
    }
}
