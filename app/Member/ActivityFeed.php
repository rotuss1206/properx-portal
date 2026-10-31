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
 * what they did (uploads, downloads, certificates; sign-ins and sign-outs are left out), staff's reviews of their license
 * documents, new tax documents, profile changes (section names only), membership status
 * changes and payments (amount, currency and plan stored from invoice.paid). Each line has an
 * icon on a coloured tile ("tone": green, yellow, blue, red) as in the mockup. Nothing from
 * other members; no file names, license numbers or field values.
 */
final class ActivityFeed
{
    /** Done by the member. */
    private const OWN_ACTIONS = ['document_uploaded', 'document_downloaded', 'certificate_generated', 'certificate_downloaded'];

    /** Profile fields grouped as the profile page's cards. */
    private const PROFILE_SECTIONS = [
        'Contact information' => ['first_name', 'last_name', 'phone', 'address'],
        'Business details' => ['firm_name', 'npn'],
        'License information' => ['license_number', 'license_state', 'license_type', 'license_expires_at'],
    ];

    /**
     * @return Collection<int, array{icon: string, tone: string, title: string, detail: ?string, at: CarbonInterface}>
     */
    public static function for(User $user, int $limit = 20): Collection
    {
        $profile = $user->memberProfile;
        $licenseDocuments = LicenseDocument::query()->whereBelongsTo($user)->get()->keyBy('id');
        $taxDocuments = TaxDocument::query()->whereBelongsTo($user)->get()->keyBy('id');

        $logs = AuditLog::query()
            ->where(function (Builder $query) use ($user, $profile, $licenseDocuments, $taxDocuments) {
                $query->where(fn (Builder $query) => $query->where('user_id', $user->getKey())->whereIn('action', self::OWN_ACTIONS))
                    ->orWhere(fn (Builder $query) => $query->whereIn('action', ['document_verified', 'document_rejected'])
                        ->where('resource_type', (new LicenseDocument)->getMorphClass())
                        ->whereIn('resource_id', $licenseDocuments->keys()))
                    ->orWhere(fn (Builder $query) => $query->where('action', 'document_uploaded')
                        ->where('resource_type', (new TaxDocument)->getMorphClass())
                        ->whereIn('resource_id', $taxDocuments->keys()));

                if ($profile !== null) {
                    $query->orWhere(fn (Builder $query) => $query->whereIn('action', ['payment_status_changed', 'profile_updated'])
                        ->where('resource_type', $profile->getMorphClass())
                        ->where('resource_id', $profile->getKey()));
                }
            })
            ->latest('id')
            ->limit($limit * 2)
            ->get()
            ->map(fn (AuditLog $log) => self::describe($log, $licenseDocuments, $taxDocuments))
            ->filter();

        $payments = $profile === null ? collect() : StripeEvent::query()
            ->where('type', 'invoice.paid')
            ->where('member_profile_id', $profile->getKey())
            ->whereNotNull('amount')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StripeEvent $event) => [
                'icon' => 'card',
                'tone' => 'green',
                'title' => 'Membership payment processed',
                'detail' => trim(($event->plan_name ? $event->plan_name.' · ' : '').Number::currency($event->amount / 100, in: $event->currency ?: 'USD'), ' ·'),
                'at' => $event->created_at,
            ]);

        return $logs->concat($payments)
            ->sortByDesc(fn (array $item) => $item['at']->getTimestamp())
            ->values()
            ->take($limit)
            ->values();
    }

    /**
     * @param  Collection<int, LicenseDocument>  $licenseDocuments
     * @param  Collection<int, TaxDocument>  $taxDocuments
     * @return array{icon: string, tone: string, title: string, detail: ?string, at: CarbonInterface}|null
     */
    private static function describe(AuditLog $log, Collection $licenseDocuments, Collection $taxDocuments): ?array
    {
        $type = $log->resource_type;
        $license = $type === (new LicenseDocument)->getMorphClass() ? $licenseDocuments->get($log->resource_id) : null;
        $tax = $type === (new TaxDocument)->getMorphClass() ? $taxDocuments->get($log->resource_id) : null;

        // Safe captions: the document type and year, never a file name or license number.
        $licenseCaption = $license ? (config('portal.member_area.license_document_types')[$license->document_type] ?? 'License document') : null;
        $taxCaption = $tax ? $tax->document_type.' · '.$tax->tax_year : null;

        $line = match ($log->action) {
            'profile_updated' => ['profile', 'blue', 'Profile updated', self::profileSections($log->details['fields'] ?? [])],
            'document_uploaded' => match (true) {
                $tax !== null => ['tax', 'blue', 'Tax document available', $taxCaption],
                default => ['license', 'blue', 'License document uploaded', $licenseCaption],
            },
            'document_downloaded' => match ($type) {
                (new TaxDocument)->getMorphClass() => ['tax', 'blue', 'Tax document downloaded', $taxCaption],
                (new LibraryDocument)->getMorphClass() => ['library', 'blue', 'Library document downloaded', null],
                default => ['license', 'blue', 'License document downloaded', $licenseCaption],
            },
            'certificate_generated' => ['certificate', 'yellow', 'Membership certificate generated', $log->created_at->year.' certificate · PDF'],
            'certificate_downloaded' => ['certificate', 'yellow', 'Membership certificate downloaded', $log->created_at->year.' certificate · PDF'],
            'document_verified' => ['document-check', 'green', 'License document approved', $licenseCaption],
            'document_rejected' => ['license', 'red', 'License document needs attention', 'Please upload a new document'],
            'payment_status_changed' => ['calendar', 'blue', 'Membership status updated', self::statusChange($log->details ?? [])],
            default => null,
        };

        if ($line === null) {
            return null;
        }

        [$icon, $tone, $title, $detail] = $line;

        return ['icon' => $icon, 'tone' => $tone, 'title' => $title, 'detail' => $detail, 'at' => $log->created_at];
    }

    /**
     * @param  list<string>  $fields
     */
    private static function profileSections(array $fields): ?string
    {
        $sections = collect(self::PROFILE_SECTIONS)
            ->filter(fn (array $sectionFields) => array_intersect($sectionFields, $fields) !== [])
            ->keys();

        return $sections->isEmpty() ? null : $sections->implode(', ');
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
