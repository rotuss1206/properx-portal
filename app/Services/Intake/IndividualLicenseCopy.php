<?php

namespace App\Services\Intake;

use App\Enums\VerificationStatus;
use App\Models\IntakeSubmission;
use App\Models\LicenseDocument;
use App\Services\Documents\DocumentVault;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The license copy uploaded with a paid application on properx.org (plugin 2.3.0). The plugin sends it
 * after the application itself; it becomes the member's License Document (resident, pending review),
 * stored encrypted like any other. Each entry's copy is stored once, whoever repeats the delivery.
 */
class IndividualLicenseCopy
{
    /** The application has not been received (or is being processed): the plugin tries again later. */
    public const PENDING = 'intake_pending';

    /**
     * @param  array{entry_id: string, file_name: string}  $data
     * @param  string  $contents  The file's bytes, already checked (type and size) by the controller
     * @return array{outcome: string, license_document_id?: int}
     */
    public function handle(array $data, string $contents, string $mimeType): array
    {
        $vault = app(DocumentVault::class);

        return DB::transaction(function () use ($data, $contents, $mimeType, $vault) {
            $submission = IntakeSubmission::query()
                ->where('source', IndividualIntake::SOURCE)
                ->where('entry_id', (string) $data['entry_id'])
                ->lockForUpdate()
                ->first();

            if ($submission === null || $submission->outcome === 'processing') {
                return ['outcome' => self::PENDING];
            }

            if ($submission->license_document_id !== null) {
                return ['outcome' => 'already_stored', 'license_document_id' => $submission->license_document_id];
            }

            $profile = $submission->memberProfile;

            // Kept out (24-month rule), a staff or agency account left untouched, or a removed member:
            // the copy has no member to belong to.
            if (! in_array($submission->outcome, ['created', 'linked'], true) || $profile === null || $profile->agency_id !== null) {
                return ['outcome' => 'skipped'];
            }

            $path = $vault->put(LicenseDocument::DIRECTORY, config('portal.uploads.mime_types')[$mimeType], $contents, encrypt: true);

            try {
                $document = (new LicenseDocument)->forceFill([
                    'user_id' => $profile->user_id,
                    'stored_filename' => $path,
                    'original_filename' => self::fileName($data['file_name'], $mimeType),
                    'mime_type' => $mimeType,
                    'size' => strlen($contents),
                    'document_type' => 'resident',
                    'license_number' => $profile->license_number,
                    'license_state' => $profile->license_state,
                    'uploaded_at' => now(),
                    'verification_status' => VerificationStatus::Pending,
                ]);
                $document->save(); // DocumentObserver audits the upload and tells staff.

                $submission->update(['license_document_id' => $document->getKey()]);
            } catch (Throwable $e) {
                $vault->delete($path);

                throw $e;
            }

            return ['outcome' => 'stored', 'license_document_id' => $document->getKey()];
        });
    }

    /**
     * The applicant's file name without any folder, with an extension that matches the contents.
     */
    private static function fileName(string $name, string $mimeType): string
    {
        $base = trim(basename(str_replace('\\', '/', $name)));
        $base = $base === '' ? 'license' : mb_substr($base, 0, 200);
        $extension = config('portal.uploads.mime_types')[$mimeType];

        return in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), [$extension, $extension === 'jpg' ? 'jpeg' : $extension], true)
            ? $base
            : $base.'.'.$extension;
    }
}
