<?php

namespace App\Member;

use App\Enums\VerificationStatus;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\User;
use App\Notifications\Member\AgencyInvitationNotice;
use App\Notifications\Member\LicenseDocumentReviewed;
use App\Notifications\Member\LicenseNeedsAttention;
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
     * @return Collection<int, array{id: string, title: string, detail: ?string, at: CarbonInterface, unread: bool, url: ?string}>
     */
    public static function latest(User $user, int $limit = 10): Collection
    {
        $notifications = $user->notifications()->latest()->limit($limit)->get();
        $rejected = self::rejectedDocuments($user, $notifications);

        return $notifications
            ->map(fn (DatabaseNotification $notification) => self::describe($notification, $rejected))
            ->filter()
            ->values();
    }

    /**
     * The rejected documents these notifications are about. Their reasons stay encrypted on the
     * document; the notification itself keeps ids only.
     *
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return Collection<int, LicenseDocument>
     */
    private static function rejectedDocuments(User $user, Collection $notifications): Collection
    {
        $ids = $notifications
            ->filter(fn (DatabaseNotification $notification) => $notification->type === LicenseDocumentReviewed::class
                && ($notification->data['status'] ?? null) === VerificationStatus::Rejected->value)
            ->map(fn (DatabaseNotification $notification) => $notification->data['document_id'] ?? null)
            ->filter();

        return $ids->isEmpty()
            ? new Collection
            : $user->licenseDocuments()->whereKey($ids->all())->where('verification_status', VerificationStatus::Rejected)->get()->keyBy('id')->toBase();
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
            LicenseDocumentReviewed::class => self::licenseDocumentTarget($notification),
            RenewalDue::class => route('member.dashboard'),
            AgencyInvitationNotice::class => route('member.dashboard'),
            LicenseNeedsAttention::class => ($notification->data['kind'] ?? null) === LicenseNeedsAttention::OWN
                ? route('member.profile', ['edit' => 'business'])
                : route('member.dashboard'),
            default => null,
        };
    }

    /**
     * The reviewed document, if it is still there and the page is open to this person.
     */
    private static function licenseDocumentTarget(DatabaseNotification $notification): string
    {
        $user = $notification->notifiable;

        if (! $user instanceof User || ! MemberMenu::visible($user, 'license-documents')) {
            return route('member.dashboard', ['tab' => 'documents', 'filter' => 'license']);
        }

        $id = $notification->data['document_id'] ?? null;

        return $id !== null && $user->licenseDocuments()->whereKey($id)->exists()
            ? route('member.license-documents', ['document' => $id])
            : route('member.license-documents');
    }

    /**
     * @param  Collection<int, LicenseDocument>  $rejected
     * @return array{id: string, title: string, detail: ?string, at: CarbonInterface, unread: bool, url: ?string}|null
     */
    private static function describe(DatabaseNotification $notification, Collection $rejected): ?array
    {
        $data = $notification->data;

        $title = match ($notification->type) {
            LicenseDocumentReviewed::class => ($data['status'] ?? null) === 'approved'
                ? 'License document approved'
                : 'License document rejected',
            TaxDocumentAvailable::class => 'New tax document available',
            RenewalDue::class => self::renewalTitle($data['paid_through'] ?? null),
            AgencyInvitationNotice::class => self::invitationTitle($notification),
            LicenseNeedsAttention::class => self::licenseTitle($notification),
            default => null,
        };

        if ($title === null) {
            return null;
        }

        $reason = match (true) {
            $notification->type === LicenseDocumentReviewed::class && ($data['status'] ?? null) === VerificationStatus::Rejected->value => $rejected->get($data['document_id'] ?? 0)?->rejection_reason,
            $notification->type === LicenseNeedsAttention::class => self::licenseProfile($notification)?->license_verification_note,
            default => null,
        };

        return [
            'id' => $notification->getKey(),
            'title' => $title,
            'detail' => $reason ? 'Reason: '.$reason : null,
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

    /**
     * The person's own license, or one of the principal's people (by name, while still on the
     * principal's agency). Once fixed or verified the notice stays, without the reason.
     */
    private static function licenseTitle(DatabaseNotification $notification): ?string
    {
        if (($notification->data['kind'] ?? null) === LicenseNeedsAttention::OWN) {
            return 'Your license needs attention';
        }

        $profile = self::licenseProfile($notification);

        return $profile === null ? null : trim($profile->first_name.' '.$profile->last_name).': license needs attention';
    }

    /**
     * The profile a license notice is about: the person's own, or one on the principal's agency;
     * null once it is neither (or no longer needs attention, for the reason).
     */
    private static function licenseProfile(DatabaseNotification $notification): ?MemberProfile
    {
        $user = $notification->notifiable;

        if (! $user instanceof User) {
            return null;
        }

        $query = MemberProfile::query()->whereKey($notification->data['profile_id'] ?? 0);

        if (($notification->data['kind'] ?? null) === LicenseNeedsAttention::OWN) {
            $query->where('user_id', $user->getKey());
        } else {
            $agencyId = $user->memberProfile?->agency_id;

            if ($agencyId === null || ! $user->memberProfile->isPrincipalOfAgency()) {
                return null;
            }

            $query->where('agency_id', $agencyId);
        }

        return $query->first();
    }

    /**
     * The principal's notices about their people's invitations: names of people still on the
     * principal's own agency only.
     */
    private static function invitationTitle(DatabaseNotification $notification): ?string
    {
        $user = $notification->notifiable;
        $agencyId = $user instanceof User ? $user->memberProfile?->agency_id : null;

        if ($agencyId === null) {
            return null;
        }

        $names = MemberProfile::query()
            ->where('agency_id', $agencyId)
            ->whereKey((array) ($notification->data['profile_ids'] ?? []))
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (MemberProfile $profile) => trim($profile->first_name.' '.$profile->last_name))
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return null;
        }

        if (($notification->data['kind'] ?? null) === AgencyInvitationNotice::REQUESTED) {
            return $names->first().' asked for a new invitation';
        }

        $shown = $names->take(3)->implode(', ');
        $more = $names->count() - 3;

        return 'Not yet set up: '.$shown.($more > 0 ? " and {$more} more" : '');
    }
}
