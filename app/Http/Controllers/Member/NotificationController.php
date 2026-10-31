<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notifications panel of the member area: opening one marks it read and leads to its page;
 * "Mark all read" clears the rest. Notifications are never marked read just by showing them.
 * Only the member's own notifications are reachable (looked up through their relation).
 */
class NotificationController extends Controller
{
    public function open(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return redirect(NotificationFeed::target($record) ?? route('member.dashboard'));
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson()
            ? response()->json(['unread' => 0])
            : redirect()->back(fallback: route('member.dashboard'));
    }
}
