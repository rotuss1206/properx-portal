<?php

namespace App\Member;

use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\User;

/**
 * Pages of the member area (/member) in menu order, and who
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
        'agency' => ['My agency', 'badge-check'],
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
                $url = self::movedUrl($user, $key) ?? route('member.'.$key);
                $items[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'url' => $url, 'active' => $key === $current];
            }
        }

        return $items;
    }

    /**
     * Agency people hold NPN and resident state only (no license document uploads). Billing is
     * everyone's: the principal manages the agency's, other agency people learn who pays
     * (App\Member\BillingPage). "My agency" is the principal's only. License documents and the
     * library close while the membership is restricted (Spec 7).
     */
    public static function visible(User $user, string $key): bool
    {
        $profile = $user->memberProfile;
        $inAgency = $profile?->agency_id !== null;

        return match ($key) {
            'license-documents' => (! $inAgency || (bool) config('portal.member_area.agency_people.license_upload'))
                && $user->can('viewAny', LicenseDocument::class),
            'agency' => AgencyPage::visible($user),
            'library' => $user->can('viewAny', LibraryDocument::class),
            default => array_key_exists($key, self::PAGES),
        };
    }

    /**
     * Where a page lives apart from its own address: tax documents on the dashboard, notification
     * preferences on the profile. Null: the page is the member area's, built or "coming soon".
     */
    public static function movedUrl(User $user, string $key): ?string
    {
        return match ($key) {
            'tax-documents' => route('member.dashboard', ['tab' => 'documents', 'filter' => 'tax']),
            'notifications' => route('member.profile').'#notifications',
            default => null,
        };
    }

    public static function label(string $key): string
    {
        return self::PAGES[$key][0];
    }
}
