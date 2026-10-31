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
    public static function latest(User $user, int $limit = 10): Collection
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
     * Where a notification leads (its link marks it read first, NotificationController::open).
     */
    public static function target(DatabaseNotification $notification): ?string
    {
        return match ($notification->type) {
            TaxDocumentAvailable::class => route('member.dashboard', ['tab' => 'documents', 'filter' => 'tax']),
            LicenseDocumentReviewed::class => route('member.dashboard', ['tab' => 'documents', 'filter' => 'license']),
            RenewalDue::class => route('member.dashboard'),
            default => null,
        };
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

        return [
            'id' => $notification->getKey(),
            'title' => $title,
            'at' => $notification->created_at,
            'unread' => $notification->read_at === null,
            'url' => route('member.notifications.open', $notification->getKey()),
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
