<?php

namespace App\Http\Controllers\Member;

use App\Exceptions\CertificateUnavailableException;
use App\Http\Controllers\Controller;
use App\Member\MemberMenu;
use App\Member\Overview;
use App\Services\Certificates\CertificateGeneratorService;
use App\Services\Certificates\CertificateOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's certificate: options, format and a live preview (member/certificate-preview, the
 * design of Certificate Generate.jpg); public/portal/certificate.js shows the choices without a round
 * trip, so no member data travels in a snapshot. "Generate & download": the PDF (its own design,
 * pdf.certificate-body) is a plain GET form to certificates.open, which checks access again; the PNG is
 * drawn by the browser from the preview after png() has checked access, recorded the issue and
 * audited it. A member who cannot have one (Overview::certificate: inactive membership, ended period,
 * expired license, incomplete profile) reads why instead; no certificate data is sent then. Nothing is
 * generated or audited by looking at this page.
 */
class CertificateController extends Controller
{
    public function show(Request $request, CertificateGeneratorService $certificates): Response
    {
        $user = $request->user();
        $overview = Overview::for($user);
        $certificate = $overview->certificate();
        $profile = $overview->profile();
        $ready = $certificate['ready'];

        return response()->view('member.certificate', [
            'page' => 'certificate',
            'title' => 'Membership certificate',
            'certificate' => $certificate,
            'profile' => $profile,
            'membership' => $overview->membership(),
            'document' => $ready ? $certificates->document($profile) : null,
            'terms' => $ready ? $certificates->terms($profile) : [],
            'renewUrl' => $certificate['canRenew'] ? config('portal.payments.payment_url') : null,
            'canUploadLicense' => MemberMenu::visible($user, 'license-documents'),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Before the browser draws the PNG: the same access as a PDF download, the term's first issue
     * date (which the browser then prints), and the audit entry. Answers the file name and the date.
     */
    public function png(Request $request, CertificateGeneratorService $certificates): JsonResponse
    {
        $profile = Overview::for($request->user())->profile();

        abort_if($profile === null || Gate::denies('downloadCertificate', $profile), 403);

        $options = CertificateOptions::fromRequest($request);

        try {
            $filename = $certificates->issuePng($profile, $options);
        } catch (CertificateUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'filename' => $filename,
            'issued_on' => $certificates->issue($profile, $options)->format('M j, Y'),
        ]);
    }
}
