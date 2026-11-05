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
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
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
    private const TEMPLATE_VERSION = 8;

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
        // Agency people hold NPN and resident state only (Agency spec 3.5), employees not even
        // that: their certificate needs no license data.
        $required = $profile->agency_id !== null
            ? array_diff_key(self::REQUIRED_FIELDS, ['license_expires_at' => true])
            : self::REQUIRED_FIELDS;

        $missing = collect($required)
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
        if ($profile->agency_id === null && LicenseStatus::fromExpiryDate($profile->license_expires_at) === LicenseStatus::Expired) {
            throw CertificateUnavailableException::licenseExpired($profile->license_expires_at);
        }
    }

    /**
     * "Valid through" on the certificate and the public check. Agency people: the end of the
     * agency's paid period (null while it has none yet, then the field is left out). Individual
     * members, by portal.member_area.certificate.valid_through: the end of their paid period,
     * or the license expiry while there is no Stripe date yet (or when set to license_expiry).
     */
    public static function validThrough(MemberProfile $profile): ?CarbonInterface
    {
        if ($profile->agency_id !== null) {
            return $profile->agency?->paid_through_at;
        }

        return config('portal.member_area.certificate.valid_through') === 'paid_through'
            ? ($profile->paid_through_at ?? $profile->license_expires_at)
            : $profile->license_expires_at;
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
        $this->ensureFontCacheDirectory();

        $pdf = Pdf::loadView('pdf.certificate', $this->viewData($data) + [
            'fontPath' => fn (string $file) => self::chrootPath('fonts/'.$file),
        ])
            ->setPaper('letter', 'portrait');

        $disk->put($path, $pdf->output());

        AuditLog::record('certificate_generated', $profile);

        return new GeneratedCertificate($this->diskName(), $path, $this->filename($profile), fromCache: false);
    }

    /**
     * What resources/views/pdf/certificate-body.blade.php prints, for the HTML preview: the same
     * data and images as the PDF. Eligibility is the caller's check.
     *
     * @return array<string, mixed>
     */
    public function document(MemberProfile $profile): array
    {
        return $this->viewData($this->certificateData($profile));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function viewData(array $data): array
    {
        return $data + [
            'qrCode' => $this->qrCode($data['verificationUrl']),
            'signatureImage' => $this->signatureImage(),
            'logoImage' => self::embedded('logo-color.png', 'image/png'),
            'sealImage' => self::embedded('seal.svg', 'image/svg+xml'),
            'diamondImage' => self::embedded('diamond.svg', 'image/svg+xml'),
            'linkImage' => self::embedded('link.svg', 'image/svg+xml'),
        ];
    }

    private static function embedded(string $file, string $type): string
    {
        return "data:{$type};base64,".base64_encode((string) file_get_contents(resource_path('views/pdf/images/'.$file)));
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
        $signatory = config('portal.member_area.certificate.signatory');
        $second = config('portal.member_area.certificate.second_signatory');
        $validThrough = self::validThrough($profile);
        $memberName = $profile->fullName();

        return [
            'memberName' => $memberName,
            'nameSize' => self::nameSize($memberName),
            'memberId' => self::formatMemberId($profile),
            'memberSince' => $profile->user->created_at->toDateString(),
            'validThrough' => $validThrough?->toDateString(),
            'term' => self::term($validThrough),
            'certificateCode' => $profile->certificate_code,
            // Name null: the longest-serving Executive Director.
            'signatoryName' => $signatory['name'] ?? User::role(Role::ExecutiveDirector->value)->oldest('id')->first()?->name ?? $signatory['title'],
            'signatoryTitle' => $signatory['title'],
            'secondSignatoryName' => $second['name'] ?? null,
            'secondSignatoryTitle' => $second['title'] ?? null,
            'verificationUrl' => route('certificates.verify', $profile->certificate_code),
        ];
    }

    /**
     * The name stays on one line: the mockup's size in points for most, smaller for long names.
     */
    private static function nameSize(string $name): int
    {
        $length = mb_strlen($name);

        return match (true) {
            $length <= 24 => 45,
            $length <= 34 => 32,
            $length <= 48 => 24,
            default => 18,
        };
    }

    /**
     * "2026", or "2026–2027" for a year that does not end in December. Null without a date.
     */
    private static function term(?CarbonInterface $validThrough): ?string
    {
        if ($validThrough === null) {
            return null;
        }

        $start = $validThrough->copy()->subYear()->addDay()->year;

        return $start === $validThrough->year ? (string) $start : $start.'–'.$validThrough->year;
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

    /**
     * The container's code is read-only and its storage is reset on every deploy, so the dompdf
     * font cache lives under the writable storage/framework/cache and is created on demand.
     */
    private function ensureFontCacheDirectory(): void
    {
        File::ensureDirectoryExists(config('dompdf.options.font_cache'));
    }

    /**
     * The real signature, once the client provides it; until then the name is set in Lora.
     */
    private function signatureImage(): ?string
    {
        $path = $this->signatureImagePath();

        return $path !== null && is_file($path)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path))
            : null;
    }

    /**
     * Absolute path of a file inside the dompdf chroot (resources/views/pdf); dompdf refuses anything outside it.
     */
    private static function chrootPath(string $relative): string
    {
        return str_replace('\\', '/', config('dompdf.options.chroot').'/'.$relative);
    }

    private function signatureImagePath(): ?string
    {
        return config('portal.certificates.signature_image');
    }

    private function filename(MemberProfile $profile): string
    {
        return "ProPerX-membership-certificate-{$profile->certificate_code}.pdf";
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
