<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\MemberMenu;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Member area pages of stage 1 (docs/specs/member-area.md): the dashboard heading and
 * "Coming soon" placeholders for the other menu items. Pages the person may not see
 * (e.g. Billing for agency people other than the principal) answer 404.
 */
class PageController extends Controller
{
    public function dashboard(Request $request): View
    {
        $profile = $request->user()->memberProfile;
        $hour = (int) now(config('portal.schedule_timezone'))->format('G');

        return view('member.dashboard', [
            'page' => 'dashboard',
            'title' => MemberMenu::label('dashboard'),
            'greeting' => match (true) {
                $hour < 12 => 'Good morning',
                $hour < 18 => 'Good afternoon',
                default => 'Good evening',
            },
            'firstName' => $profile?->first_name ?? $request->user()->name,
        ]);
    }

    public function placeholder(Request $request, string $page): View
    {
        abort_unless(MemberMenu::visible($request->user(), $page), 404);

        return view('member.placeholder', ['page' => $page, 'title' => MemberMenu::label($page)]);
    }
}
