<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membership certificate PDF (ТЗ, розділ 3.6).
 * TODO: 24h cache with regeneration on data change and a public verification page.
 */
class CertificateController extends Controller
{
    public function __invoke(MemberProfile $memberProfile): Response
    {
        Gate::authorize('downloadCertificate', $memberProfile);

        $signatory = User::role(Role::ExecutiveDirector->value)->oldest()->first();

        AuditLog::record('certificate_downloaded', $memberProfile);

        return Pdf::loadView('certificates.membership', [
            'profile' => $memberProfile,
            'memberSince' => $memberProfile->user->created_at,
            'issuedAt' => now(),
            'signatory' => $signatory,
        ])
            ->setPaper('letter', 'landscape')
            ->stream("membership-certificate-{$memberProfile->certificate_code}.pdf");
    }
}
