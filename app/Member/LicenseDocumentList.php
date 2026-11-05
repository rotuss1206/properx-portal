<?php

namespace App\Member;

use App\Enums\VerificationStatus;
use App\Models\LicenseDocument;
use App\Models\User;
use App\Support\UsStates;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * The member's own license documents for /member/license-documents, newest first. Staff's
 * internal admin_notes are never read here; a rejected document shows rejection_reason only.
 */
final class LicenseDocumentList
{
    public const FILTERS = ['all' => 'All documents', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    private const FORMATS = ['application/pdf' => 'PDF', 'image/jpeg' => 'JPG', 'image/png' => 'PNG'];

    /**
     * @return Collection<int, array{id: int, name: string, type: string, status: string, variant: string, statusKey: string, uploadedAt: ?CarbonInterface, size: string, format: string, reason: ?string, canWithdraw: bool, downloadUrl: string, number: ?string, state: ?string, expiresAt: ?CarbonInterface, preview: ?string, previewUrl: string}>
     */
    public static function for(User $user): Collection
    {
        $types = config('portal.member_area.license_document_types');

        return LicenseDocument::query()
            ->whereBelongsTo($user)
            ->latest('uploaded_at')
            ->latest('id')
            ->get()
            ->map(function (LicenseDocument $document) use ($user, $types) {
                $status = $document->verification_status ?? VerificationStatus::Pending;

                return [
                    'id' => $document->getKey(),
                    'name' => (string) $document->original_filename,
                    'type' => $types[$document->document_type] ?? 'License document',
                    'status' => $status->label(),
                    'variant' => $status->variant(),
                    'statusKey' => $status->value,
                    'uploadedAt' => $document->uploaded_at,
                    'size' => Number::fileSize((int) $document->size, maxPrecision: 1),
                    'format' => self::FORMATS[$document->mime_type] ?? 'File',
                    'reason' => $status === VerificationStatus::Rejected ? $document->rejection_reason : null,
                    'canWithdraw' => $user->can('delete', $document),
                    'downloadUrl' => route('member.documents.download', ['type' => 'license', 'document' => $document->getKey()]),
                    'number' => $document->license_number,
                    'state' => $document->license_state === null ? null : (UsStates::ALL[$document->license_state] ?? $document->license_state),
                    'expiresAt' => $document->license_expires_at,
                    'preview' => self::previewKind($document),
                    'previewUrl' => route('member.license-documents.preview', $document->getKey()),
                ];
            });
    }

    /**
     * How the panel previews a document: an image in place, a PDF in a new tab (framing it would
     * need an exception to X-Frame-Options and frame-ancestors), null for files too large to
     * decrypt just to look at, or of any other type.
     *
     * @return 'image'|'pdf'|null
     */
    public static function previewKind(LicenseDocument $document): ?string
    {
        if ((int) $document->size > (int) config('portal.member_area.preview_max_kilobytes') * 1024) {
            return null;
        }

        return match ($document->mime_type) {
            'image/jpeg', 'image/png' => 'image',
            'application/pdf' => 'pdf',
            default => null,
        };
    }
}
