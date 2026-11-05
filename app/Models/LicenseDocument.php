<?php

namespace App\Models;

use App\Contracts\DownloadableDocument;
use App\Enums\VerificationStatus;
use App\Observers\DocumentObserver;
use Database\Factories\LicenseDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['original_filename', 'mime_type', 'size', 'document_type', 'uploaded_at', 'verification_status', 'admin_notes', 'rejection_reason', 'license_number', 'license_state', 'license_expires_at'])]
#[Hidden(['stored_filename'])]
#[ObservedBy(DocumentObserver::class)]
class LicenseDocument extends Model implements DownloadableDocument
{
    /** @use HasFactory<LicenseDocumentFactory> */
    use HasFactory;

    public const DIRECTORY = 'license-documents';

    /**
     * Document types that are a license: uploading one sends the member's license back to review
     * and states its details. "other" is a supporting file.
     */
    public const LICENSE_TYPES = ['resident', 'non_resident'];

    public static function disk(): string
    {
        return config('portal.documents_disk');
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            // License document metadata is encrypted at rest (KEENEY rule); the file itself too.
            'original_filename' => 'encrypted',
            'mime_type' => 'encrypted',
            'admin_notes' => 'encrypted', // internal, never shown to the member
            'rejection_reason' => 'encrypted', // written for the member
            'license_number' => 'encrypted',
            'license_expires_at' => 'date',
            'verification_status' => VerificationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function downloadType(): string
    {
        return 'license';
    }

    public function storagePath(): string
    {
        return $this->stored_filename;
    }

    public function downloadName(): string
    {
        return $this->original_filename;
    }

    public function downloadMimeType(): string
    {
        return $this->mime_type;
    }
}
