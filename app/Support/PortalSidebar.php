<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Data for the bottom of the Nova sidebar (resources/js/nova/sidebar.js): the signed-in
 * person's initials, name and Member ID (staff: their role), and the "Renewal due" card.
 * Shown to that person only; never logged.
 */
final class PortalSidebar
{
    /**
     * @return array{logo: string, icons: string, user: array{initials: string, name: string, caption: ?string}|null, renewal: array{days: int, paymentUrl: ?string}|null}
     */
    public static function for(?User $user): array
    {
        return [
            'logo' => asset('portal/img/logo-portal.png'),
            'icons' => PortalAsset::url('img/icons.svg'),
            'user' => $user ? self::person($user) : null,
            'renewal' => RenewalNotice::for($user),
        ];
    }

    /**
     * @return array{initials: string, name: string, caption: ?string}
     */
    private static function person(User $user): array
    {
        $profile = $user->isStaff() ? null : $user->memberProfile;
        $name = $profile ? $profile->fullName() : (string) $user->name;

        $caption = $profile?->member_number
            ? '#'.$profile->member_number
            : collect(Role::staff())->first(fn (Role $role) => $user->hasRole($role->value))?->value;

        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->pipe(fn ($letters) => $letters->count() > 1 ? $letters->first().$letters->last() : $letters->first());

        return ['initials' => (string) $initials, 'name' => $name, 'caption' => $caption];
    }
}
