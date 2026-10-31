<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\MemberMenu;
use App\Member\Overview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Member area pages of stage 1 (docs/specs/member-area.md): the dashboard heading and
 * "Coming soon" placeholders for the other menu items. Pages the person may not see
 * (e.g. Billing for agency people other than the principal) answer 404.
 */
class PageController extends Controller
{
    /**
     * The dashboard: tabs (App\Livewire\Member\Dashboard); the notifications panel (open by
     * default here) carries the license upload for individual members.
     */
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $overview = Overview::for($user);

        return view('member.dashboard', [
            'page' => 'dashboard',
            'title' => MemberMenu::label('dashboard'),
            // The server's guess (portal time zone); public/portal/member.js switches it to the
            // visitor's own time of day.
            'greeting' => self::greeting((int) now(config('portal.schedule_timezone'))->format('G')),
            'firstName' => $overview->profile()?->first_name ?? $user->name,
            'agency' => $overview->agency(),
            'canUpload' => $overview->canUpload(),
            'documentTypes' => config('portal.member_area.license_document_types'),
        ]);
    }

    public static function greeting(int $hour): string
    {
        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    public function placeholder(Request $request, string $page): View
    {
        abort_unless(MemberMenu::visible($request->user(), $page), 404);

        return view('member.placeholder', ['page' => $page, 'title' => MemberMenu::label($page)]);
    }
}
