<?php

namespace App\Models;

use App\Contracts\DownloadableDocument;
use App\Observers\DocumentObserver;
use App\Services\Documents\DocumentVault;
use Database\Factories\TaxDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_type', 'tax_year', 'original_filename', 'uploaded_at'])]
#[Hidden(['stored_filename'])]
#[ObservedBy(DocumentObserver::class)]
class TaxDocument extends Model implements DownloadableDocument
{
    /** @use HasFactory<TaxDocumentFactory> */
    use HasFactory;

    public const DOCUMENT_TYPES = ['1099-NEC', '1099-MISC', 'W-9', 'Dues Receipt'];

    public const DIRECTORY = 'tax-documents';

    public static function disk(): string
    {
        return config('portal.documents_disk');
    }

    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'uploaded_at' => 'datetime',
            'downloaded_at' => 'datetime',
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
        return 'tax';
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
        return DocumentVault::mimeTypeForPath($this->stored_filename);
    }
}
