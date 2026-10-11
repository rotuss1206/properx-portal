<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Database\Factories\LicenseDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['original_filename', 'mime_type', 'size', 'uploaded_at', 'verification_status', 'admin_notes'])]
#[Hidden(['stored_filename'])]
class LicenseDocument extends Model
{
    /** @use HasFactory<LicenseDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
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
}
