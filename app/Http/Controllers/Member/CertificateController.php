<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Member\MemberMenu;
use App\Member\Overview;
use App\Services\Certificates\CertificateGeneratorService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's certificate (docs/design/Certificate Generate.jpg): details, the HTML preview of the
 * very page the PDF prints, and Download PDF through certificates.open, which checks access again.
 * A member who cannot have one (Overview::certificate: inactive membership, expired license,
 * incomplete profile) reads why instead; no certificate data is sent then. Nothing is generated or
 * audited by looking at this page.
 */
class CertificateController extends Controller
{
    public function show(Request $request, CertificateGeneratorService $certificates): Response
    {
        $user = $request->user();
        $overview = Overview::for($user);
        $certificate = $overview->certificate();
        $profile = $overview->profile();
        $restricted = (bool) $profile?->hasRestrictedMembership();

        return response()->view('member.certificate', [
            'page' => 'certificate',
            'title' => 'Membership certificate',
            'certificate' => $certificate,
            'membership' => $overview->membership(),
            'document' => $certificate['ready'] ? $certificates->document($profile) : null,
            'renewUrl' => $restricted ? config('portal.payments.payment_url') : null,
            'canUploadLicense' => MemberMenu::visible($user, 'license-documents'),
        ])->header('Cache-Control', 'no-store, private');
    }
}
