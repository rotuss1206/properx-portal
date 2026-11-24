<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use App\Services\Documents\DocumentVault;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A license copy uploaded for a person who does not exist yet (agency enrollment, "Add people").
 * The file is in the documents vault, encrypted, under a random name; the form keeps only this
 * record's id, and only its owner (the draft or the agency) can use it. Once the person is created
 * it becomes their License Document, pending review (attachTo). Unused files are pruned.
 */
class PendingLicenseFile extends Model
{
    use HasUlids, Prunable;

    public const OWNER_DRAFT = 'draft';

    public const OWNER_AGENCY = 'agency';

    /** Kept this many days unused (a draft lives 30 days after its last save). */
    public const KEEP_DAYS = 31;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'original_filename' => 'encrypted',
            'mime_type' => 'encrypted',
        ];
    }

    /**
     * Stores an upload (already validated with DocumentVault::uploadRules()) for this owner.
     */
    public static function store(UploadedFile $file, string $ownerType, int $ownerId): self
    {
        $stored = app(DocumentVault::class)->store($file, LicenseDocument::DIRECTORY, encrypt: true);

        return self::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'stored_filename' => $stored->path,
            'original_filename' => $stored->originalName,
            'mime_type' => $stored->mimeType,
            'size' => $stored->size,
        ]);
    }

    /**
     * The owner's file with this id, or null (another owner's id finds nothing).
     */
    public static function findFor(?string $id, string $ownerType, ?int $ownerId): ?self
    {
        if ($id === null || $id === '' || $ownerId === null) {
            return null;
        }

        return self::query()->whereKey($id)->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first();
    }

    /**
     * Hands the file to the owner's new person as a License Document (pending review) with the
     * license details given with it. The vault file is kept; this record goes.
     *
     * @param  array{license_number?: ?string, license_state?: ?string}  $details
     */
    public function attachTo(User $user, array $details): LicenseDocument
    {
        $document = (new LicenseDocument)->forceFill([
            'user_id' => $user->getKey(),
            'stored_filename' => $this->stored_filename,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'document_type' => 'resident',
            'license_number' => $details['license_number'] ?? null,
            'license_state' => $details['license_state'] ?? null,
            'uploaded_at' => now(),
            'verification_status' => VerificationStatus::Pending,
        ]);
        $document->save(); // DocumentObserver audits the upload and tells staff.

        $this->delete();

        return $document;
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * The file goes with an unused record.
     */
    protected function pruning(): void
    {
        try {
            app(DocumentVault::class)->delete($this->stored_filename);
        } catch (Throwable $e) {
            Log::warning('An unused license copy could not be deleted', ['id' => $this->getKey(), 'error' => $e::class]);
        }
    }
}
