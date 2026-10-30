<?php

namespace App\Member;

use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The member's own documents for the dashboard (Recent documents, Documents tab): license and
 * tax documents the policies let them see, plus the membership certificate. No file names
 * (they may carry personal data): the type and year describe each row.
 */
final class DocumentList
{
    public const FILTERS = ['all' => 'All', 'license' => 'License', 'tax' => 'Tax', 'certificate' => 'Certificate'];

    /**
     * @return Collection<int, array{kind: string, icon: string, name: string, detail: ?string, type: string, status: string, variant: string, updated: ?CarbonInterface, url: ?string}>
     */
    public static function for(User $user, string $filter = 'all'): Collection
    {
        $rows = collect();

        if (in_array($filter, ['all', 'license'], true) && $user->can('viewAny', LicenseDocument::class)) {
            $types = config('portal.member_area.license_document_types');

            foreach (LicenseDocument::query()->whereBelongsTo($user)->get() as $document) {
                [$status, $variant] = match ($document->verification_status) {
                    VerificationStatus::Approved => ['Approved', 'success'],
                    VerificationStatus::Rejected => ['Needs attention', 'danger'],
                    default => ['Pending review', 'warning'],
                };

                $rows->push([
                    'kind' => 'license',
                    'icon' => 'license',
                    'name' => $types[$document->document_type] ?? 'License document',
                    'detail' => 'Uploaded '.$document->uploaded_at?->toFormattedDateString(),
                    'type' => 'License',
                    'status' => $status,
                    'variant' => $variant,
                    'updated' => $document->updated_at ?? $document->uploaded_at,
                    'url' => route('member.documents.download', ['type' => 'license', 'document' => $document->getKey()]),
                ]);
            }
        }

        if (in_array($filter, ['all', 'tax'], true) && $user->can('viewAny', TaxDocument::class)) {
            foreach (TaxDocument::query()->whereBelongsTo($user)->get() as $document) {
                $rows->push([
                    'kind' => 'tax',
                    'icon' => 'tax',
                    'name' => (string) $document->document_type,
                    'detail' => 'Tax year '.$document->tax_year,
                    'type' => 'Tax',
                    'status' => 'Available',
                    'variant' => 'success',
                    'updated' => $document->uploaded_at,
                    'url' => route('member.documents.download', ['type' => 'tax', 'document' => $document->getKey()]),
                ]);
            }
        }

        if (in_array($filter, ['all', 'certificate'], true) && ($profile = $user->memberProfile)) {
            $certificate = Overview::for($user)->certificate();
            $generated = AuditLog::query()
                ->where('action', 'certificate_generated')
                ->where('resource_type', $profile->getMorphClass())
                ->where('resource_id', $profile->getKey())
                ->latest('id')
                ->value('created_at');

            $rows->push([
                'kind' => 'certificate',
                'icon' => 'certificate',
                'name' => 'Membership certificate',
                'detail' => $certificate['ready'] && $certificate['validThrough']
                    ? 'Valid through '.$certificate['validThrough']->toFormattedDateString()
                    : 'Not available',
                'type' => 'Certificate',
                'status' => $certificate['ready'] ? 'Ready' : 'Unavailable',
                'variant' => $certificate['ready'] ? 'success' : 'neutral',
                'updated' => $generated ? Carbon::parse($generated) : null,
                'url' => $certificate['url'],
            ]);
        }

        return $rows
            ->sortByDesc(fn (array $row) => $row['updated']?->getTimestamp() ?? 0)
            ->values();
    }

    public static function recent(User $user, int $limit = 3): Collection
    {
        return self::for($user)->take($limit);
    }
}
