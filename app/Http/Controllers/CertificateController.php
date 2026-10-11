<?php

namespace App\Http\Controllers;

use App\Exceptions\CertificateUnavailableException;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the membership certificate PDF through the app (no direct file URLs),
 * after checking role and ownership (MemberProfilePolicy::downloadCertificate).
 */
class CertificateController extends Controller
{
    public function download(MemberProfile $memberProfile, CertificateGeneratorService $certificates): Response
    {
        Gate::authorize('downloadCertificate', $memberProfile);

        try {
            $certificate = $certificates->generate($memberProfile);
        } catch (CertificateUnavailableException $e) {
            return response()->view('certificates.unavailable', ['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        AuditLog::record('certificate_downloaded', $memberProfile);

        return Storage::disk($certificate->disk)->download($certificate->path, $certificate->filename, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
