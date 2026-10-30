<?php

namespace App\Member;

use App\Models\User;
use App\Notifications\Member\LicenseDocumentReviewed;
use App\Notifications\Member\RenewalDue;
use App\Notifications\Member\TaxDocumentAvailable;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The member's in-app notifications (database channel) as text: the stored payload holds ids,
 * enum values and dates only, so the wording is written here when they are shown.
 */
final class NotificationFeed
{
    /**
     * @return Collection<int, array{id: string, title: string, at: CarbonInterface, unread: bool, url: ?string}>
     */
    public static function latest(User $user, int $limit = 5): Collection
    {
        return $user->notifications()->latest()->limit($limit)->get()
            ->map(fn (DatabaseNotification $notification) => self::describe($notification))
            ->filter()
            ->values();
    }

    public static function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * "Marked as read when opened": the panel shows them, then they count as read.
     *
     * @param  Collection<int, array{id: string, unread: bool}>  $items
     */
    public static function markRead(User $user, Collection $items): void
    {
        $ids = $items->where('unread', true)->pluck('id');

        if ($ids->isNotEmpty()) {
            $user->unreadNotifications()->whereIn('id', $ids)->update(['read_at' => now()]);
        }
    }

    /**
     * @return array{id: string, title: string, at: CarbonInterface, unread: bool, url: ?string}|null
     */
    private static function describe(DatabaseNotification $notification): ?array
    {
        $data = $notification->data;

        $title = match ($notification->type) {
            LicenseDocumentReviewed::class => ($data['status'] ?? null) === 'approved'
                ? 'License document approved'
                : 'License document needs attention',
            TaxDocumentAvailable::class => 'New tax document available',
            RenewalDue::class => self::renewalTitle($data['paid_through'] ?? null),
            default => null,
        };

        if ($title === null) {
            return null;
        }

        $url = match ($notification->type) {
            TaxDocumentAvailable::class => route('member.dashboard', ['tab' => 'documents', 'filter' => 'tax']),
            LicenseDocumentReviewed::class => route('member.dashboard', ['tab' => 'documents', 'filter' => 'license']),
            default => null,
        };

        return [
            'id' => $notification->getKey(),
            'title' => $title,
            'at' => $notification->created_at,
            'unread' => $notification->read_at === null,
            'url' => $url,
        ];
    }

    private static function renewalTitle(?string $paidThrough): string
    {
        if ($paidThrough === null) {
            return 'Membership renewal reminder';
        }

        $days = (int) today()->diffInDays(Carbon::parse($paidThrough)->startOfDay(), false);

        return match (true) {
            $days > 1 => "Renewal reminder — {$days} days",
            $days === 1 => 'Renewal reminder — 1 day',
            $days === 0 => 'Membership renews today',
            default => 'Membership renewal is due',
        };
    }
}
