<?php

namespace App\Services\Certificates;

use App\Enums\LicenseStatus;
use App\Enums\Role;
use App\Exceptions\CertificateUnavailableException;
use App\Models\AuditLog;
use App\Models\CertificateIssue;
use App\Models\MemberProfile;
use App\Models\StripeEvent;
use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
    private const TEMPLATE_VERSION = 12;

    /**
     * The PDF's design (docs/design/Certificate.jpg) draws the Letter page 816 px wide; the page is 612 pt.
     */
    private const PDF_WIDTH = 816;

    /**
     * The PDF design's pixels as points on the page.
     */
    public static function points(float $designPixels): string
    {
        return round($designPixels * 612 / self::PDF_WIDTH, 3).'pt';
    }

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
        $canRenew = $profile->agency_id === null || $profile->agency?->principal_user_id === $profile->user_id;

        if ($profile->hasRestrictedMembership()) {
            throw CertificateUnavailableException::membershipInactive($canRenew);
        }

        // Check the date itself too, in case the nightly status update has not run yet.
        if ($profile->agency_id === null && LicenseStatus::fromExpiryDate($profile->license_expires_at) === LicenseStatus::Expired) {
            throw CertificateUnavailableException::licenseExpired($profile->license_expires_at);
        }

        // Never a certificate that is out of date the moment it is printed, also during the
        // grace period, when the rest of the member area stays open. /verify-certificate agrees.
        $validThrough = self::validThrough($profile);

        if ($validThrough !== null && LicenseStatus::fromExpiryDate($validThrough) === LicenseStatus::Expired) {
            throw CertificateUnavailableException::periodEnded($canRenew);
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
    public function generate(MemberProfile $profile, ?CertificateOptions $options = null): GeneratedCertificate
    {
        $this->assertEligible($profile);

        $options ??= new CertificateOptions;
        // The first issue of this term fixes the date printed on every copy of it.
        $this->issue($profile, $options);
        $data = $this->certificateData($profile, $options);
        $auditDetails = $options->auditDetails($data['term']);
        $path = $this->directory($profile).'/'.$this->fingerprint($data).'.pdf';
        $disk = $this->disk();

        if ($disk->exists($path) && $disk->lastModified($path) >= $this->cacheLimit()) {
            return new GeneratedCertificate($this->diskName(), $path, $this->filename($profile), fromCache: true, auditDetails: $auditDetails);
        }

        // Each combination of choices has its own file; only outdated ones go.
        $this->pruneExpired($profile);
        $this->ensureFontCacheDirectory();

        $pdf = Pdf::loadView('pdf.certificate', $this->viewData($data) + [
            'fontPath' => fn (string $file) => self::chrootPath('fonts/'.$file),
        ])
            ->setPaper('letter', 'portrait');

        $disk->put($path, $pdf->output());

        AuditLog::record('certificate_generated', $profile, details: $auditDetails);

        return new GeneratedCertificate($this->diskName(), $path, $this->filename($profile), fromCache: false, auditDetails: $auditDetails);
    }

    /**
     * The PNG (/member/certificate draws its preview in the browser): eligibility, the first issue of
     * the term, and the audit entry, as for a PDF. Returns the file name, the PDF's with .png.
     *
     * @throws CertificateUnavailableException
     */
    public function issuePng(MemberProfile $profile, CertificateOptions $options): string
    {
        $this->assertEligible($profile);
        $this->issue($profile, $options);
        $data = $this->certificateData($profile, $options);

        AuditLog::record('certificate_generated', $profile, details: $options->auditDetails($data['term'], 'png'));

        return substr($this->filename($profile), 0, -4).'.png';
    }

    /**
     * The day this member's certificate for the chosen term was first issued, recorded now if it never was.
     */
    public function issue(MemberProfile $profile, CertificateOptions $options): CarbonInterface
    {
        $termEnd = $this->termEnd($profile, $options)?->toDateString();

        if ($issued = self::issuedOn($profile, $termEnd)) {
            return $issued;
        }

        try {
            return CertificateIssue::query()->create([
                'member_profile_id' => $profile->getKey(),
                'term_end' => $termEnd,
                'issued_on' => now(config('portal.schedule_timezone'))->toDateString(),
            ])->issued_on;
        } catch (UniqueConstraintViolationException) {
            // Two downloads at the same moment: the first one's date.
            return self::issuedOn($profile, $termEnd);
        }
    }

    /**
     * The first issue of a term, or null when it was never issued.
     */
    private static function issuedOn(MemberProfile $profile, ?string $termEnd): ?CarbonInterface
    {
        return CertificateIssue::query()
            ->where('member_profile_id', $profile->getKey())
            ->when($termEnd, fn ($query) => $query->whereDate('term_end', $termEnd), fn ($query) => $query->whereNull('term_end'))
            ->first()
            ?->issued_on;
    }

    /**
     * The date shown by /verify-certificate: the first issue of the latest term, or null before any.
     */
    public static function lastIssuedOn(MemberProfile $profile): ?CarbonInterface
    {
        return CertificateIssue::query()->where('member_profile_id', $profile->getKey())->orderByDesc('term_end')->first()?->issued_on;
    }

    private function termEnd(MemberProfile $profile, CertificateOptions $options): ?CarbonInterface
    {
        // Only a term this member really has; anything else in a link means the current one.
        $chosen = collect($this->terms($profile))->firstWhere('value', $options->termEnd);

        return $chosen ? Carbon::parse($chosen['value']) : self::validThrough($profile);
    }

    /**
     * What the certificate prints, for the HTML preview (member/certificate-preview): the same data
     * and images as the PDF. Eligibility is the caller's check.
     *
     * @return array<string, mixed>
     */
    public function document(MemberProfile $profile, ?CertificateOptions $options = null): array
    {
        return $this->viewData($this->certificateData($profile, $options ?? new CertificateOptions));
    }

    /**
     * The terms a member can choose, the current first. An earlier one only where a payment in that
     * year proves it: invoice.paid events keep no period dates, so periods count back a year at a
     * time from the current end, as the membership timeline does. Agencies have no such history.
     *
     * @return list<array{value: string, label: string, date: string, term: string}>
     */
    public function terms(MemberProfile $profile): array
    {
        $current = self::validThrough($profile);

        if ($current === null) {
            return [];
        }

        $describe = fn (CarbonInterface $end) => [
            'value' => $end->toDateString(),
            'label' => (string) self::term($end),
            'date' => $end->format('M j, Y'),
            'term' => (string) self::term($end),
        ];

        $terms = [$describe($current)];

        if ($profile->agency_id !== null) {
            return $terms;
        }

        $payments = StripeEvent::query()
            ->where('member_profile_id', $profile->getKey())
            ->where('type', 'invoice.paid')
            ->get()
            ->map(fn (StripeEvent $event) => $event->event_created_at ?? $event->created_at);

        if ($payments->isEmpty()) {
            return $terms;
        }

        $earliest = $payments->min();

        for ($end = $current->copy()->subYear(); $end->greaterThan($earliest); $end = $end->copy()->subYear()) {
            $start = $end->copy()->subYear();

            // A renewal is paid as its period starts.
            if ($payments->contains(fn ($paid) => $paid->greaterThanOrEqualTo($start) && $paid->lessThan($end))) {
                $terms[] = $describe($end);
            }
        }

        return $terms;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function viewData(array $data): array
    {
        return $data + [
            'preview' => false,
            'qrCode' => $this->qrCode($data['verificationUrl']),
            'signatureImage' => $this->signatureImage(),
            'logoImage' => self::embedded('logo-color.png', 'image/png'),
            'sealImage' => self::embedded('seal.svg', 'image/svg+xml'),
            'linkImage' => self::embedded('link.svg', 'image/svg+xml'),
            'diamondImage' => self::embedded('diamond.svg', 'image/svg+xml'),
            // The preview sits on a cream gradient: the transparent logo (the PNG has a white ground).
            'previewLogoImage' => 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents(public_path('portal/img/logo-color.svg'))),
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
    public static function downloadUrl(MemberProfile $profile, ?CertificateOptions $options = null): string
    {
        return URL::temporarySignedRoute(
            'certificates.download',
            now()->addMinutes(config('portal.temporary_url_minutes')),
            ['memberProfile' => $profile->getKey()] + ($options?->toQuery() ?? []),
        );
    }

    /**
     * Drop all cached PDFs of the member.
     */
    public function forget(MemberProfile $profile): void
    {
        $this->disk()->deleteDirectory($this->directory($profile));
    }

    private function pruneExpired(MemberProfile $profile): void
    {
        $disk = $this->disk();

        foreach ($disk->files($this->directory($profile)) as $file) {
            if ($disk->lastModified($file) < $this->cacheLimit()) {
                $disk->delete($file);
            }
        }
    }

    private function cacheLimit(): int
    {
        return now()->subHours($this->cacheHours())->getTimestamp();
    }

    /**
     * Everything printed on the certificate (and therefore part of the cache key).
     *
     * @return array<string, mixed>
     */
    private function certificateData(MemberProfile $profile, CertificateOptions $options): array
    {
        $signatory = config('portal.member_area.certificate.signatory');
        $second = config('portal.member_area.certificate.second_signatory');
        $memberName = $profile->fullName();

        $validThrough = $this->termEnd($profile, $options);

        // The first issue of this term (certificate_issues), the same on every copy; before any, the
        // preview shows today, the day it would be issued.
        $issuedOn = self::issuedOn($profile, $validThrough?->toDateString()) ?? now(config('portal.schedule_timezone'));

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
            'issuedOnLabel' => $issuedOn->format('M j, Y'),
            'showMemberId' => $options->memberId,
            'showJoinDate' => $options->joinDate,
            'showQr' => $options->qr,
        ];
    }

    /**
     * The name stays on one line: the design's 62 px for most, smaller for long names. In points.
     */
    private static function nameSize(string $name): float
    {
        $length = mb_strlen($name);

        return (float) self::points(match (true) {
            $length <= 30 => 62,
            $length <= 42 => 46,
            $length <= 56 => 34,
            default => 26,
        });
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
