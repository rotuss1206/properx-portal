<?php

namespace App\Support;

use App\Models\User;
use App\Nova\MemberProfile as MemberProfileResource;
use App\Nova\Staff;
use Laravel\Nova\Nova;

/**
 * Data for the shared header of the member area (components/member/header.blade.php) and Nova
 * (resources/js/nova/header.js): today's date, the person's initials and name, and where the
 * account menu's Profile and Security entries lead. Search: Nova's, for staff in Nova only.
 */
final class PortalHeader
{
    /**
     * @param  'member'|'nova'  $area
     * @return array{date: string, dateIso: string, initials: string, name: string, profileUrl: ?string, securityUrl: string, search: bool, icons: string, unread: int}
     */
    public static function for(User $user, string $area): array
    {
        $today = now(config('portal.schedule_timezone'));
        $person = PortalSidebar::for($user)['user'];

        return [
            'date' => $today->format('l, j F Y'),
            'dateIso' => $today->toDateString(),
            'initials' => $person['initials'] ?? '',
            'name' => $person['name'] ?? '',
            'profileUrl' => self::profileUrl($user, $area),
            'securityUrl' => route('nova.pages.user-security'),
            'search' => $area === 'member'
                ? (bool) config('portal.member_area.header_search')
                : $user->isStaff(),
            'icons' => PortalAsset::url('img/icons.svg'),
            // Member area bell (database notifications); Nova's bell counts Nova's own.
            'unread' => $area === 'member' ? $user->unreadNotifications()->count() : 0,
        ];
    }

    /**
     * Staff: their own account in Nova. Members: their profile (the member area's page, or the
     * Nova profile while they still use Nova).
     */
    private static function profileUrl(User $user, string $area): ?string
    {
        if ($area === 'member') {
            return route('member.profile');
        }

        if ($user->isStaff()) {
            return Nova::url('/resources/'.Staff::uriKey().'/'.$user->getKey());
        }

        $profile = $user->memberProfile;

        return $profile ? Nova::url('/resources/'.MemberProfileResource::uriKey().'/'.$profile->getKey()) : null;
    }
}
