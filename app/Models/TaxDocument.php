<?php

namespace App\Models;

use Database\Factories\TaxDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_type', 'tax_year', 'original_filename', 'uploaded_at'])]
#[Hidden(['stored_filename'])]
class TaxDocument extends Model
{
    /** @use HasFactory<TaxDocumentFactory> */
    use HasFactory;

    public const DOCUMENT_TYPES = ['1099-NEC', '1099-MISC', 'W-9', 'Dues Receipt'];

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
}
