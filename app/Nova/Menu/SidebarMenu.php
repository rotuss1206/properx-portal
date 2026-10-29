<?php

namespace App\Nova\Menu;

use App\Models\MemberProfile;
use App\Models\User;
use App\Nova\Agency;
use App\Nova\AuditLog;
use App\Nova\Dashboards\Main;
use App\Nova\Dashboards\MyMembership;
use App\Nova\LibraryDocument;
use App\Nova\LicenseDocument;
use App\Nova\Member;
use App\Nova\MemberProfile as MemberProfileResource;
use App\Nova\PartnerCode;
use App\Nova\ReminderLog;
use App\Nova\Staff;
use App\Nova\TaxDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;

/**
 * The Nova sidebar (Nova::mainMenu). Items and access come from here only: resource and
 * dashboard items keep Nova's own authorization, links check the profile policy. The
 * sidebar script (resources/js/nova/sidebar.js) only draws them.
 *
 * A new Nova resource must be added here to show up in the sidebar.
 */
final class SidebarMenu
{
    /** Query parameter that tells the profile pages which block the member came for. */
    public const SECTION = 'section';

    /**
     * @return array<int, MenuSection>
     */
    public static function for(Request $request): array
    {
        $user = $request->user();

        if ($user instanceof User && $user->isMember() && ! $user->isStaff()) {
            return [MenuSection::make('Menu', self::memberItems($user))];
        }

        // Without the groups the role cannot open (Nova would keep an empty heading).
        return array_values(array_filter(
            self::staffSections(),
            fn (MenuSection $section) => $section->items->authorized($request)->isNotEmpty(),
        ));
    }

    /**
     * Member: Dashboard, Profile, License documents, Tax documents, Certificate, Billing,
     * Compliance library, Notifications. Certificate and Notifications open blocks of the
     * profile pages; Billing appears once portal.menu.billing_path is set.
     *
     * @return array<int, PortalMenuItem>
     */
    private static function memberItems(User $user): array
    {
        $profile = $user->memberProfile;
        $profilePath = $profile ? '/resources/'.MemberProfileResource::uriKey().'/'.$profile->getKey() : null;
        $billingPath = config('portal.menu.billing_path');

        return array_values(array_filter([
            PortalMenuItem::dashboard(MyMembership::class)->name('Dashboard')->icon('dashboard'),

            $profilePath ? PortalMenuItem::link('Profile', $profilePath)
                ->icon('profile')
                ->activeWhen(fn (Request $request) => self::onProfilePage($request, $profilePath, null))
                ->canSee(fn () => Gate::forUser($user)->check('view', $profile)) : null,

            PortalMenuItem::resource(LicenseDocument::class)->name('License documents')->icon('license'),

            PortalMenuItem::resource(TaxDocument::class)->name('Tax documents')->icon('tax'),

            $profilePath ? PortalMenuItem::link('Certificate', $profilePath.'?'.self::SECTION.'=certificate')
                ->icon('certificate')
                ->activeWhen(fn (Request $request) => self::onProfilePage($request, $profilePath, 'certificate'))
                ->canSee(fn () => self::canSeeCertificate($user, $profile)) : null,

            filled($billingPath) ? PortalMenuItem::link('Billing', $billingPath)->icon('billing') : null,

            PortalMenuItem::resource(LibraryDocument::class)->name('Compliance library')->icon('library'),

            $profilePath ? PortalMenuItem::link('Notifications', $profilePath.'/edit?'.self::SECTION.'=notifications')
                ->icon('notifications')
                ->activeWhen(fn (Request $request) => self::onProfilePage($request, $profilePath.'/edit', 'notifications'))
                ->canSee(fn () => Gate::forUser($user)->check('update', $profile)) : null,
        ]));
    }

    /**
     * Staff: the same groups as Nova's default menu, each resource behind its own policy.
     *
     * @return array<int, MenuSection>
     */
    private static function staffSections(): array
    {
        return [
            MenuSection::make('Menu', [
                PortalMenuItem::dashboard(Main::class)->name('Dashboard')->icon('dashboard'),
            ]),
            MenuSection::make('Members', [
                PortalMenuItem::resource(Member::class)->icon('profile'),
                PortalMenuItem::resource(MemberProfileResource::class)->icon('profile'),
            ]),
            MenuSection::make('Documents', [
                PortalMenuItem::resource(LicenseDocument::class)->icon('license'),
                PortalMenuItem::resource(TaxDocument::class)->icon('tax'),
                PortalMenuItem::resource(LibraryDocument::class)->icon('library'),
            ]),
            MenuSection::make('Agencies', [
                PortalMenuItem::resource(Agency::class)->icon('dashboard'),
                PortalMenuItem::resource(PartnerCode::class)->icon('billing'),
            ]),
            MenuSection::make('Logs', [
                PortalMenuItem::resource(AuditLog::class)->icon('tax'),
                PortalMenuItem::resource(ReminderLog::class)->icon('notifications'),
            ]),
            MenuSection::make('Administration', [
                PortalMenuItem::resource(Staff::class)->icon('profile'),
            ]),
        ];
    }

    private static function canSeeCertificate(User $user, MemberProfile $profile): bool
    {
        $gate = Gate::forUser($user);

        return $gate->check('view', $profile) && $gate->check('downloadCertificate', $profile);
    }

    /**
     * On the given profile page, and there for the given block (null: no block).
     */
    private static function onProfilePage(Request $request, string $path, ?string $section): bool
    {
        $prefix = trim(Nova::path(), '/');
        $current = '/'.trim($prefix === '' ? $request->path() : Str::after($request->path(), $prefix), '/');

        if ($section === null) {
            $onProfile = $current === $path || $current === $path.'/edit';

            return $onProfile && ! in_array($request->query(self::SECTION), ['certificate', 'notifications'], true);
        }

        return $current === $path && $request->query(self::SECTION) === $section;
    }
}
