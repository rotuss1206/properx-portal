<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\MemberMenu;
use App\Member\Overview;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's certificate: options, format and a live preview
 * of the very page the PDF prints; public/portal/certificate.js shows the choices without a round
 * trip, so no member data travels in a snapshot. "Generate & download" is a plain GET form to
 * certificates.open, which checks access again. A member who cannot have one (Overview::certificate:
 * inactive membership, ended period, expired license, incomplete profile) reads why instead; no
 * certificate data is sent then. Nothing is generated or audited by looking at this page.
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
            'document' => $ready ? ['preview' => true] + $certificates->document($profile) : null,
            'terms' => $ready ? $certificates->terms($profile) : [],
            'landscape' => CertificateGeneratorService::orientation() === 'landscape',
            'renewUrl' => $certificate['canRenew'] ? config('portal.payments.payment_url') : null,
            'canUploadLicense' => MemberMenu::visible($user, 'license-documents'),
        ])->header('Cache-Control', 'no-store, private');
    }
}
