<?php

namespace App\Member;

use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\User;
use App\Nova\LibraryDocument as LibraryDocumentResource;
use App\Nova\LicenseDocument as LicenseDocumentResource;
use App\Nova\MemberProfile as MemberProfileResource;
use App\Nova\Menu\SidebarMenu;
use Laravel\Nova\Nova;

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
     * Agency people hold NPN and resident state only (no license document uploads) and the
     * principal pays the agency's invoice, so only the principal sees Billing. License
     * documents and the library close while the membership is restricted (Spec 7).
     */
    public static function visible(User $user, string $key): bool
    {
        $profile = $user->memberProfile;
        $inAgency = $profile?->agency_id !== null;

        return match ($key) {
            'license-documents' => (! $inAgency || (bool) config('portal.member_area.agency_people.license_upload'))
                && $user->can('viewAny', LicenseDocument::class),
            'billing' => ! $inAgency || ($profile->isAgencyPrincipal() && config('portal.member_area.agency_people.principal_billing')),
            'library' => $user->can('viewAny', LibraryDocument::class),
            default => array_key_exists($key, self::PAGES),
        };
    }

    /**
     * Where a page lives until the member area has its own: tax documents on the dashboard,
     * notification preferences on the profile, the others in Nova (kept open for members by
     * SendMembersToMemberArea). Null: the page is the member area's, built or "coming soon".
     */
    public static function movedUrl(User $user, string $key): ?string
    {
        $profile = $user->memberProfile;
        $profilePath = $profile ? '/resources/'.MemberProfileResource::uriKey().'/'.$profile->getKey() : null;

        return match (true) {
            $key === 'tax-documents' => route('member.dashboard', ['tab' => 'documents', 'filter' => 'tax']),
            $key === 'license-documents' => Nova::url('/resources/'.LicenseDocumentResource::uriKey()),
            $key === 'library' => Nova::url('/resources/'.LibraryDocumentResource::uriKey()),
            $key === 'notifications' => route('member.profile').'#notifications',
            $key === 'certificate' && $profilePath !== null => Nova::url($profilePath.'?'.SidebarMenu::SECTION.'=certificate'),
            default => null,
        };
    }

    public static function label(string $key): string
    {
        return self::PAGES[$key][0];
    }
}
