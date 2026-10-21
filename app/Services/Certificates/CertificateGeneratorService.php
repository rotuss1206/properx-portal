<?php

namespace App\Services\Certificates;

use App\Enums\LicenseStatus;
use App\Enums\Role;
use App\Exceptions\CertificateUnavailableException;
use App\Models\AuditLog;
use App\Models\MemberProfile;
use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Membership certificate PDF (Spec 2, 3.6).
 *
 * PDFs are cached per member for 24 hours. The cache file name is a fingerprint
 * of everything printed on the certificate, so changed data never serves a stale
 * PDF; MemberProfileObserver additionally clears the cache on relevant changes.
 */
class CertificateGeneratorService
{
    /**
     * Bump when the template changes so cached PDFs are regenerated.
     */
    private const TEMPLATE_VERSION = 2;

    /**
     * @var array<string, string> attribute => human-readable name
     */
    private const REQUIRED_FIELDS = [
        'first_name' => 'first name',
        'last_name' => 'last name',
        'license_expires_at' => 'license expiry date',
        'certificate_code' => 'certificate number',
    ];

    /**
     * @throws CertificateUnavailableException
     */
    public function assertEligible(MemberProfile $profile): void
    {
        $missing = collect(self::REQUIRED_FIELDS)
            ->filter(fn (string $label, string $attribute) => blank($profile->{$attribute}))
            ->values()
            ->all();

        if ($missing !== []) {
            throw CertificateUnavailableException::missingFields($missing);
        }

        // Canceled, or past due beyond the grace period (Spec 7).
        if ($profile->hasRestrictedMembership()) {
            throw CertificateUnavailableException::membershipInactive();
        }

        // Check the date itself too, in case the nightly status update has not run yet.
        if (LicenseStatus::fromExpiryDate($profile->license_expires_at) === LicenseStatus::Expired) {
            throw CertificateUnavailableException::licenseExpired($profile->license_expires_at);
        }
    }

    /**
     * @throws CertificateUnavailableException
     */
    public function generate(MemberProfile $profile): GeneratedCertificate
    {
        $this->assertEligible($profile);

        $data = $this->certificateData($profile);
        $path = $this->directory($profile).'/'.$this->fingerprint($data).'.pdf';
        $disk = $this->disk();

        if ($disk->exists($path) && $disk->lastModified($path) >= now()->subHours($this->cacheHours())->getTimestamp()) {
            return new GeneratedCertificate($this->diskName(), $path, $this->filename($profile), fromCache: true);
        }

        $this->forget($profile);

        $pdf = Pdf::loadView('pdf.certificate', $data + [
            'issuedAt' => now(),
            'qrCode' => $this->qrCode($data['verificationUrl']),
            'signatureImage' => $this->signatureImage(),
        ])
            ->setPaper('letter', 'landscape')
            ->setOption('isFontSubsettingEnabled', true);

        $disk->put($path, $pdf->output());

        AuditLog::record('certificate_generated', $profile);

        return new GeneratedCertificate($this->diskName(), $path, $this->filename($profile), fromCache: false);
    }

    /**
     * Short-lived signed link to the download route; the route also re-checks the session
     * and MemberProfilePolicy::downloadCertificate before streaming the cached blob.
     */
    public static function downloadUrl(MemberProfile $profile): string
    {
        return URL::temporarySignedRoute(
            'certificates.download',
            now()->addMinutes(config('portal.temporary_url_minutes')),
            ['memberProfile' => $profile->getKey()],
        );
    }

    /**
     * Drop all cached PDFs of the member.
     */
    public function forget(MemberProfile $profile): void
    {
        $this->disk()->deleteDirectory($this->directory($profile));
    }

    /**
     * Everything printed on the certificate (and therefore part of the cache key).
     *
     * @return array<string, mixed>
     */
    private function certificateData(MemberProfile $profile): array
    {
        $signatory = User::role(Role::ExecutiveDirector->value)->oldest('id')->first();

        return [
            'memberName' => $profile->fullName(),
            'firmName' => $profile->firm_name,
            'memberId' => self::formatMemberId($profile),
            // Membership validity: from joining the Alliance until the license expiry date.
            'validFrom' => $profile->user->created_at->toDateString(),
            'validThrough' => $profile->license_expires_at->toDateString(),
            'certificateCode' => $profile->certificate_code,
            'signatoryName' => $signatory?->name ?? 'Executive Director',
            'verificationUrl' => route('certificates.verify', $profile->certificate_code),
        ];
    }

    public static function formatMemberId(MemberProfile $profile): string
    {
        return $profile->member_number ?? MemberProfile::formatMemberNumber($profile->user_id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fingerprint(array $data): string
    {
        return sha1(json_encode([self::TEMPLATE_VERSION, $data, $this->signatureImagePath()]));
    }

    private function qrCode(string $url): string
    {
        $png = (new Writer(new GDLibRenderer(360, margin: 1)))->writeString($url);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function signatureImage(): ?string
    {
        $path = $this->signatureImagePath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        return 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path));
    }

    private function signatureImagePath(): ?string
    {
        return config('portal.certificates.signature_image');
    }

    private function filename(MemberProfile $profile): string
    {
        return "ProPerx-membership-certificate-{$profile->certificate_code}.pdf";
    }

    private function directory(MemberProfile $profile): string
    {
        return config('portal.certificates.directory').'/'.$profile->getKey();
    }

    private function cacheHours(): int
    {
        return (int) config('portal.certificates.cache_hours', 24);
    }

    private function diskName(): string
    {
        return config('portal.certificates.disk');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }
}
