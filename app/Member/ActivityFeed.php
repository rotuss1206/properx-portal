<?php

namespace App\Member;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\StripeEvent;
use App\Models\TaxDocument;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * The member's own activity (dashboard Activity tab), in plain words and without personal data:
 * what they did (sign-ins, uploads, downloads, certificates), staff's reviews of their license
 * documents and new tax documents, membership status changes, and payments (amount, currency
 * and plan stored from invoice.paid). Nothing from other members.
 */
final class ActivityFeed
{
    /** Done by the member. */
    private const OWN_ACTIONS = ['login', 'document_uploaded', 'document_downloaded', 'certificate_generated', 'certificate_downloaded'];

    /**
     * @return Collection<int, array{icon: string, title: string, detail: ?string, at: CarbonInterface}>
     */
    public static function for(User $user, int $limit = 20): Collection
    {
        $profile = $user->memberProfile;
        $licenseIds = LicenseDocument::query()->whereBelongsTo($user)->pluck('id');
        $taxIds = TaxDocument::query()->whereBelongsTo($user)->pluck('id');

        $logs = AuditLog::query()
            ->where(function (Builder $query) use ($user, $profile, $licenseIds, $taxIds) {
                $query->where(fn (Builder $query) => $query->where('user_id', $user->getKey())->whereIn('action', self::OWN_ACTIONS))
                    ->orWhere(fn (Builder $query) => $query->whereIn('action', ['document_verified', 'document_rejected'])
                        ->where('resource_type', (new LicenseDocument)->getMorphClass())
                        ->whereIn('resource_id', $licenseIds))
                    ->orWhere(fn (Builder $query) => $query->where('action', 'document_uploaded')
                        ->where('resource_type', (new TaxDocument)->getMorphClass())
                        ->whereIn('resource_id', $taxIds));

                if ($profile !== null) {
                    $query->orWhere(fn (Builder $query) => $query->where('action', 'payment_status_changed')
                        ->where('resource_type', $profile->getMorphClass())
                        ->where('resource_id', $profile->getKey()));
                }
            })
            ->latest('id')
            ->limit($limit * 3) // room for sign-ins folded into one per day
            ->get()
            ->map(fn (AuditLog $log) => self::describe($log))
            ->filter();

        $payments = $profile === null ? collect() : StripeEvent::query()
            ->where('type', 'invoice.paid')
            ->where('member_profile_id', $profile->getKey())
            ->whereNotNull('amount')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StripeEvent $event) => [
                'icon' => 'billing',
                'title' => 'Membership payment received',
                'detail' => trim(($event->plan_name ? $event->plan_name.' · ' : '').Number::currency($event->amount / 100, in: $event->currency ?: 'USD'), ' ·'),
                'at' => $event->created_at,
            ]);

        return $logs->concat($payments)
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->values()
            ->pipe(fn (Collection $items) => self::oneSignInPerDay($items))
            ->take($limit)
            ->values();
    }

    /**
     * Several sign-ins on one day read as one line (the latest), so they do not crowd out the rest.
     *
     * @param  Collection<int, array{icon: string, title: string, detail: ?string, at: CarbonInterface}>  $items
     * @return Collection<int, array{icon: string, title: string, detail: ?string, at: CarbonInterface}>
     */
    private static function oneSignInPerDay(Collection $items): Collection
    {
        $seen = [];

        return $items->filter(function (array $item) use (&$seen) {
            if ($item['title'] !== 'Signed in') {
                return true;
            }

            $day = $item['at']->toDateString();

            return ! isset($seen[$day]) && ($seen[$day] = true);
        });
    }

    /**
     * @return array{icon: string, title: string, detail: ?string, at: CarbonInterface}|null
     */
    private static function describe(AuditLog $log): ?array
    {
        $kind = match ($log->resource_type) {
            (new LicenseDocument)->getMorphClass() => 'License document',
            (new TaxDocument)->getMorphClass() => 'Tax document',
            (new LibraryDocument)->getMorphClass() => 'Library document',
            default => 'Document',
        };

        [$icon, $title, $detail] = match ($log->action) {
            'login' => ['profile', 'Signed in', null],
            'document_uploaded' => $kind === 'Tax document'
                ? ['tax', 'Tax document added', null]
                : ['license', $kind.' uploaded', null],
            'document_downloaded' => [$kind === 'Tax document' ? 'tax' : ($kind === 'Library document' ? 'library' : 'license'), $kind.' downloaded', null],
            'certificate_generated' => ['certificate', 'Membership certificate generated', null],
            'certificate_downloaded' => ['certificate', 'Membership certificate downloaded', null],
            'document_verified' => ['license', 'License document approved', null],
            'document_rejected' => ['license', 'License document needs attention', 'Please upload a new document'],
            'payment_status_changed' => ['billing', 'Membership status updated', self::statusChange($log->details ?? [])],
            default => [null, null, null],
        };

        return $title === null ? null : ['icon' => $icon, 'title' => $title, 'detail' => $detail, 'at' => $log->created_at];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function statusChange(array $details): ?string
    {
        $from = PaymentStatus::tryFrom((string) ($details['from'] ?? ''));
        $to = PaymentStatus::tryFrom((string) ($details['to'] ?? ''));

        return $to ? ($from ? $from->label().' → ' : '').$to->label() : null;
    }
}
