<?php

namespace App\Http\Controllers;

use App\Exceptions\CertificateUnavailableException;
use App\Exceptions\StorageUnavailableException;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;
use App\Services\Documents\StorageHealth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serves the membership certificate PDF through the app (no direct file URLs),
 * after checking role and ownership (MemberProfilePolicy::downloadCertificate).
 */
class CertificateController extends Controller
{
    /**
     * The "Download certificate" button on the profile page: checks access and eligibility,
     * then hands over a fresh short-lived signed download link.
     */
    public function open(MemberProfile $memberProfile, CertificateGeneratorService $certificates): Response
    {
        Gate::authorize('downloadCertificate', $memberProfile);

        try {
            $certificates->assertEligible($memberProfile);
        } catch (CertificateUnavailableException $e) {
            return response()->view('certificates.unavailable', ['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return redirect()->to(CertificateGeneratorService::downloadUrl($memberProfile));
    }

    /**
     * Storage that does not answer gives a fast "temporarily unavailable" page (503), not a
     * gateway timeout, and is not audited as a download.
     */
    public function download(MemberProfile $memberProfile, CertificateGeneratorService $certificates, StorageHealth $storage): Response
    {
        Gate::authorize('downloadCertificate', $memberProfile);

        $storage->ensureAvailable('certificate', $memberProfile->getKey());

        try {
            $certificate = $certificates->generate($memberProfile);
            $response = Storage::disk($certificate->disk)->download($certificate->path, $certificate->filename, [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (CertificateUnavailableException $e) {
            return response()->view('certificates.unavailable', ['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            throw new StorageUnavailableException('certificate', $memberProfile->getKey(), $e);
        }

        AuditLog::record('certificate_downloaded', $memberProfile);

        return $response;
    }
}
