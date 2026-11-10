<?php

namespace Tests\Feature\Member;

use App\Enums\LicenseVerification;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Member\DocumentList;
use App\Member\LicenseDocumentList;
use App\Member\NotificationFeed;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use App\Models\User;
use App\Notifications\Member\LicenseDocumentDecision;
use App\Notifications\Member\LicenseDocumentReviewed;
use App\Services\Documents\DocumentVault;
use Database\Seeders\Support\DemoFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * /member/license-documents: the list, upload, withdrawal, who may do what, and what stays out of
 * the audit log and of the member's sight.
 */
class LicenseDocumentsTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const PAGE_FROM = ['from' => 'page', 'document_type' => 'resident', 'license_number' => 'OH-2019-4471', 'license_issued_at' => '2024-10-01', 'license_expires_at' => '2027-09-30'];

    private const WRONG_FORMAT = 'Unsupported format — upload a PDF, JPG or PNG.';

    private const TOO_LARGE = 'Too large — the file may not be larger than 10 MB.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    private function pdf(string $name = 'jordan-license.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, DemoFile::pdf('License'));
    }

    private function document(User $member, VerificationStatus $status, array $attributes = []): LicenseDocument
    {
        $path = app(DocumentVault::class)->put(LicenseDocument::DIRECTORY, 'pdf', '%PDF-1.4 x', encrypt: true);

        return LicenseDocument::factory()->for($member)->create([
            'verification_status' => $status,
            'stored_filename' => $path,
            'mime_type' => 'application/pdf',
            'admin_notes' => null,
            'rejection_reason' => null,
            ...$attributes,
        ]);
    }

    private function agencyPerson(): User
    {
        $agency = $this->agency(2);

        return $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first()->user;
    }

    // The list

    public function test_an_empty_list_invites_an_upload(): void
    {
        $this->actingAs($this->member())->get(route('member.license-documents'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('No documents yet')
            ->assertSee(route('member.license-documents.upload'), false)
            ->assertDontSee('<table', false);
    }

    public function test_the_list_shows_every_status_with_name_type_and_date(): void
    {
        $member = $this->member();
        $this->document($member, VerificationStatus::Pending, ['original_filename' => 'pending.pdf', 'document_type' => 'resident']);
        $this->document($member, VerificationStatus::Approved, ['original_filename' => 'approved.pdf', 'document_type' => 'non_resident', 'uploaded_at' => '2026-03-14 10:00:00']);
        $this->document($member, VerificationStatus::Rejected, ['original_filename' => 'rejected.pdf', 'document_type' => 'other']);

        $this->actingAs($member)->get(route('member.license-documents'))->assertOk()
            ->assertSee('pending.pdf')->assertSee('approved.pdf')->assertSee('rejected.pdf')
            ->assertSee('Resident license')->assertSee('Non-resident license')->assertSee('Other')
            ->assertSee('14 Mar 2026')
            ->assertSee('ppx-badge--warning', false)->assertSee('ppx-badge--success', false)->assertSee('ppx-badge--danger', false)
            ->assertSee('Pending review')->assertSee('Approved')->assertSee('Rejected')
            ->assertDontSee('Needs attention');
    }

    public function test_the_status_has_the_same_name_on_every_page(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['license_verification' => LicenseVerification::Verified])->save();
        $pending = $this->document($member, VerificationStatus::Pending);
        $rejected = $this->document($member, VerificationStatus::Rejected);
        $member->notify(LicenseDocumentReviewed::for($rejected));

        $this->assertSame(['Pending review', 'Rejected'], DocumentList::for($member, 'license')->pluck('status')->sort()->values()->all());
        $this->assertSame(['Pending review', 'Rejected'], LicenseDocumentList::for($member)->pluck('status')->sort()->values()->all());
        $this->assertSame('License document rejected', NotificationFeed::latest($member)->first()['title']);

        $this->assertModelExists($pending);
    }

    public function test_a_rejection_shows_the_reason_for_the_member_and_never_the_internal_notes(): void
    {
        $member = $this->member();
        $this->document($member, VerificationStatus::Rejected, ['rejection_reason' => 'The scan is unreadable.', 'admin_notes' => 'Looks forged, call the state board.']);
        $approved = $this->document($member, VerificationStatus::Approved, ['rejection_reason' => 'Left over from before.', 'admin_notes' => 'Internal remark.']);

        $this->actingAs($member)->get(route('member.license-documents'))->assertOk()
            ->assertSee('The scan is unreadable.')
            ->assertDontSee('Looks forged')
            ->assertDontSee('Internal remark.')
            ->assertDontSee('Left over from before.');

        $this->get(route('member.license-documents', ['document' => $approved->id]))->assertOk()->assertDontSee('Internal remark.');
    }

    public function test_a_rejection_without_a_reason_shows_none(): void
    {
        $member = $this->member();
        $this->document($member, VerificationStatus::Rejected, ['admin_notes' => 'Internal only.']);

        $this->actingAs($member)->get(route('member.license-documents'))->assertOk()
            ->assertDontSee('Internal only.')
            ->assertDontSee('<dt>Reason</dt>', false);
    }

    public function test_the_chips_filter_by_status_and_a_document_opens_in_the_panel(): void
    {
        $member = $this->member();
        $pending = $this->document($member, VerificationStatus::Pending, ['original_filename' => 'waiting.pdf']);
        $approved = $this->document($member, VerificationStatus::Approved, ['original_filename' => 'accepted.pdf']);

        $this->actingAs($member)->get(route('member.license-documents', ['status' => 'approved']))->assertOk()
            ->assertSee('accepted.pdf')->assertDontSee('waiting.pdf');

        $this->get(route('member.license-documents', ['status' => 'rejected']))->assertOk()
            ->assertSee('No documents with this status');

        $this->get(route('member.license-documents', ['document' => $pending->id]))->assertOk()
            ->assertSee(route('member.documents.download', ['type' => 'license', 'document' => $pending->id]), false);

        $this->assertNotSame($pending->id, $approved->id);
    }

    public function test_nobody_opens_someone_elses_document(): void
    {
        $member = $this->member();
        $other = $this->document($this->member(), VerificationStatus::Pending, ['original_filename' => 'not-yours.pdf']);

        $this->actingAs($member)->get(route('member.license-documents'))->assertOk()->assertDontSee('not-yours.pdf');
        $this->get(route('member.license-documents', ['document' => $other->id]))->assertNotFound();
        $this->get(route('member.documents.download', ['type' => 'license', 'document' => $other->id]))->assertForbidden();
    }

    public function test_the_own_file_downloads_through_the_vault(): void
    {
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Approved, ['stored_filename' => app(DocumentVault::class)->put(LicenseDocument::DIRECTORY, 'pdf', '%PDF-1.4 own', encrypt: true)]);

        $this->actingAs($member);
        $signed = $this->get(route('member.documents.download', ['type' => 'license', 'document' => $document->id]))->assertRedirect()->headers->get('Location');

        $this->assertStringContainsString('%PDF-1.4 own', $this->get($signed)->assertOk()->streamedContent());
    }

    public function test_pages_run_under_the_strict_csp(): void
    {
        $member = $this->member();
        $this->document($member, VerificationStatus::Pending);
        $this->actingAs($member);

        foreach ([route('member.license-documents'), route('member.license-documents.upload')] as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bnonce=)[^>]*>/', $content);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $content);
            $this->assertStringNotContainsString('wire:click', $content);
        }
    }

    // Upload

    public function test_an_upload_is_stored_encrypted_and_pending_and_the_license_goes_back_to_review(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['license_verification' => LicenseVerification::Verified])->save();

        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM, 'document_type' => 'non_resident', 'license_state' => 'IN'])
            ->assertSessionHas('status');

        $document = LicenseDocument::query()->whereBelongsTo($member)->sole();
        $this->assertSame(VerificationStatus::Pending, $document->verification_status);
        $this->assertSame('non_resident', $document->document_type);
        $this->assertSame('jordan-license.pdf', $document->original_filename);
        $this->assertStringEndsWith('.pdf.enc', $document->stored_filename);
        $this->assertStringStartsNotWith('%PDF', Storage::disk(config('portal.documents_disk'))->get($document->stored_filename));
        $this->assertStringNotContainsString('jordan', (string) $document->getRawOriginal('original_filename'));
        $this->assertSame(LicenseVerification::PendingReview, $member->memberProfile->fresh()->license_verification);

        $this->get(route('member.license-documents', ['document' => $document->id]))->assertOk()->assertSee('jordan-license.pdf');
    }

    public function test_a_supporting_file_leaves_the_license_status_alone(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['license_verification' => LicenseVerification::Verified])->save();

        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'from' => 'page', 'document_type' => 'other'])
            ->assertSessionHasNoErrors();

        $document = LicenseDocument::query()->whereBelongsTo($member)->sole();
        $this->assertSame('other', $document->document_type);
        $this->assertNull($document->license_state);
        $this->assertSame(VerificationStatus::Pending, $document->verification_status);
        $this->assertSame(LicenseVerification::Verified, $member->memberProfile->fresh()->license_verification);

        // Also from the dashboard's short form, and a license from there does change it.
        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'other'])->assertSessionHasNoErrors();
        $this->assertSame(LicenseVerification::Verified, $member->memberProfile->fresh()->license_verification);

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'resident'])->assertSessionHasNoErrors();
        $this->assertSame(LicenseVerification::PendingReview, $member->memberProfile->fresh()->license_verification);
    }

    public function test_the_upload_form_is_prefilled_from_the_profile(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['license_number' => 'OH-2019-4471', 'license_state' => 'OH', 'license_expires_at' => '2027-09-30'])->save();

        $form = $this->actingAs($member->fresh())->get(route('member.license-documents.upload'))->assertOk()
            ->assertSee('value="OH-2019-4471"', false)
            ->assertSee('value="2027-09-30"', false)
            ->assertSee('data-profile-value="OH-2019-4471"', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<option value="OH"\s+selected/', $form);
    }

    public function test_license_details_stay_with_the_document_and_the_profile_keeps_its_own(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['license_number' => 'PROFILE-1', 'license_state' => 'TX', 'license_expires_at' => '2030-01-15'])->save();
        $member = $member->fresh();

        $this->actingAs($member)->post(route('member.license-documents.store'), [
            'file' => $this->pdf(),
            ...self::PAGE_FROM,
            'license_state' => 'FORGED',
        ])->assertSessionHasNoErrors();

        $document = LicenseDocument::query()->whereBelongsTo($member)->sole();
        $this->assertSame('OH-2019-4471', $document->license_number);
        $this->assertStringNotContainsString('OH-2019', (string) $document->getRawOriginal('license_number'));
        $this->assertSame('TX', $document->license_state, 'A resident license is in the state of the profile.');
        $this->assertSame('2024-10-01', $document->license_issued_at->toDateString());
        $this->assertSame('2027-09-30', $document->license_expires_at->toDateString());

        $profile = $member->memberProfile->fresh();
        $this->assertSame('PROFILE-1', $profile->license_number);
        $this->assertSame('TX', $profile->license_state);
        $this->assertSame('2030-01-15', $profile->license_expires_at->toDateString());

        $this->assertStringNotContainsString('OH-2019', AuditLog::query()->get()->toJson());

        $this->get(route('member.license-documents', ['document' => $document->id]))->assertOk()
            ->assertSee('OH-2019-4471')
            ->assertSee('<dt>State</dt><dd>Texas</dd>', false)
            ->assertSee('<dt>Issued</dt><dd>1 Oct 2024</dd>', false)
            ->assertSee('<dt>Expires</dt><dd>30 Sep 2027</dd>', false);

        $this->actingAs($this->staff(Role::MembershipCoordinator))->getJson("/nova-api/license-documents/{$document->id}")->assertOk()
            ->assertSee('OH-2019-4471')
            ->assertSee('Texas')
            ->assertSee('2024-10-01')
            ->assertSee('2027-09-30');
    }

    public function test_a_license_needs_its_number_and_both_dates(): void
    {
        $this->actingAs($this->member());

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'from' => 'page', 'document_type' => 'resident', 'license_number' => '', 'license_issued_at' => '', 'license_expires_at' => ''])
            ->assertSessionHasErrors([
                'license_number' => 'Enter the license number.',
                'license_issued_at' => 'Enter the date this license was issued.',
                'license_expires_at' => 'Enter the date this license expires.',
            ]);

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM, 'license_issued_at' => 'soon', 'license_expires_at' => 'later', 'license_number' => str_repeat('9', 51)])
            ->assertSessionHasErrors([
                'license_issued_at' => 'Enter the issue date as a full date, e.g. 10/01/2024.',
                'license_expires_at' => 'Enter the expiration date as a full date, e.g. 09/30/2027.',
                'license_number' => 'The license number may not be longer than 50 characters.',
            ]);

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM, 'license_issued_at' => today()->addDay()->toDateString(), 'license_expires_at' => today()->addYear()->toDateString()])
            ->assertSessionHasErrors(['license_issued_at' => 'The issue date cannot be in the future.']);

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM, 'license_issued_at' => '2025-01-01', 'license_expires_at' => '2024-01-01'])
            ->assertSessionHasErrors(['license_expires_at' => 'The expiration date cannot be before the issue date.']);

        $this->assertSame(0, LicenseDocument::query()->count());
    }

    public function test_staff_see_the_issue_date_in_nova(): void
    {
        $member = $this->member();
        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM])->assertSessionHasNoErrors();
        $document = LicenseDocument::query()->whereBelongsTo($member)->sole();

        $staff = $this->staff(Role::MembershipCoordinator);

        $fields = collect($this->actingAs($staff)->getJson("/nova-api/license-documents/{$document->getKey()}")->assertOk()->json('resource.fields'));

        $this->assertSame('2024-10-01', $fields->firstWhere('attribute', 'license_issued_at')['value']);
    }

    public function test_a_non_resident_license_asks_for_its_own_state(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['license_state' => 'TX'])->save();
        $member = $member->fresh();
        $this->actingAs($member);
        $input = [...self::PAGE_FROM, 'document_type' => 'non_resident'];

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...$input])
            ->assertSessionHasErrors(['license_state' => 'Choose the state of this license.']);
        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...$input, 'license_state' => 'ZZ'])
            ->assertSessionHasErrors(['license_state' => 'Choose the state from the list.']);

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...$input, 'license_state' => 'IN'])->assertSessionHasNoErrors();

        $document = LicenseDocument::query()->whereBelongsTo($member)->sole();
        $this->assertSame('IN', $document->license_state);
        $this->assertSame('TX', $member->memberProfile->fresh()->license_state);
    }

    public function test_a_supporting_file_keeps_no_license_details(): void
    {
        $member = $this->member();

        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'from' => 'page', 'document_type' => 'other', 'license_number' => 'IGNORED'])
            ->assertSessionHasNoErrors();

        $document = LicenseDocument::query()->whereBelongsTo($member)->sole();
        $this->assertNull($document->license_number);
        $this->assertNull($document->license_issued_at);
        $this->assertNull($document->license_expires_at);
    }

    // Several files

    public function test_each_file_becomes_its_own_document_with_the_same_details(): void
    {
        $member = $this->member();

        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => [$this->pdf('one.pdf'), $this->pdf('two.pdf')], ...self::PAGE_FROM])
            ->assertRedirect(route('member.license-documents'))
            ->assertSessionHas('status');

        $documents = LicenseDocument::query()->whereBelongsTo($member)->orderBy('id')->get();
        $this->assertSame(['one.pdf', 'two.pdf'], $documents->pluck('original_filename')->all());
        $this->assertSame(['OH-2019-4471', 'OH-2019-4471'], $documents->pluck('license_number')->all());
        $this->assertNotSame($documents[0]->stored_filename, $documents[1]->stored_filename);
        $this->assertSame(2, AuditLog::where('action', 'document_uploaded')->count());
    }

    public function test_mixed_files_keep_the_good_ones_and_name_the_rejected(): void
    {
        $member = $this->member();

        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => [
            $this->pdf('good.pdf'),
            UploadedFile::fake()->createWithContent('scan.tiff', "II*\0".str_repeat("\0", 64)),
            UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
        ], ...self::PAGE_FROM])
            ->assertRedirect(route('member.license-documents'))
            ->assertSessionHas('status')
            ->assertSessionHas('document_error', fn (string $error) => str_contains($error, 'scan.tiff: '.self::WRONG_FORMAT) && str_contains($error, 'huge.pdf: '.self::TOO_LARGE));

        $this->assertSame(['good.pdf'], LicenseDocument::query()->whereBelongsTo($member)->get()->pluck('original_filename')->all());
        $this->assertStringNotContainsString('scan.tiff', AuditLog::query()->get()->toJson());
    }

    public function test_when_every_file_is_refused_nothing_is_saved_and_the_page_lists_why(): void
    {
        $this->actingAs($this->member())->from(route('member.license-documents.upload'))->followingRedirects()
            ->post(route('member.license-documents.store'), ['file' => [
                UploadedFile::fake()->createWithContent('a.txt', 'text'),
                UploadedFile::fake()->createWithContent('b.txt', 'text'),
            ], ...self::PAGE_FROM])
            ->assertOk()
            ->assertSee('a.txt: '.self::WRONG_FORMAT)
            ->assertSee('b.txt: '.self::WRONG_FORMAT)
            ->assertSee('Rejected');

        $this->assertSame(0, LicenseDocument::query()->count());
    }

    public function test_one_file_at_a_time_as_the_page_sends_them_gets_json(): void
    {
        $this->actingAs($this->member());

        $this->post(route('member.license-documents.store'), ['file' => UploadedFile::fake()->createWithContent('a.txt', 'text'), ...self::PAGE_FROM], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', self::WRONG_FORMAT);

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_the_page_matches_the_mockup_and_promises_only_what_is_true(): void
    {
        $this->actingAs($this->member())->get(route('member.license-documents.upload'))->assertOk()
            ->assertSee('Uploaded files')
            ->assertSee('or click to browse from your device')
            ->assertSee('name="file[]" multiple', false)
            ->assertSee('Issue date')
            ->assertSee('Expiration date')
            ->assertDontSee('(optional)')
            ->assertDontSee('Selected file')
            ->assertDontSee('TLS 1.3')
            ->assertDontSee('scanned for viruses');
    }

    public function test_the_dashboards_short_form_takes_the_details_from_the_profile(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['license_number' => 'PROFILE-1', 'license_state' => 'TX', 'license_expires_at' => '2030-01-15'])->save();

        $this->actingAs($member->fresh())->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'resident'])
            ->assertRedirect(route('member.dashboard'));

        $document = LicenseDocument::query()->whereBelongsTo($member)->sole();
        $this->assertSame('PROFILE-1', $document->license_number);
        $this->assertSame('TX', $document->license_state);
    }

    // Preview

    private function stored(User $member, string $mime, string $contents, array $attributes = []): LicenseDocument
    {
        return $this->document($member, VerificationStatus::Approved, [
            'stored_filename' => app(DocumentVault::class)->put(LicenseDocument::DIRECTORY, 'bin', $contents, encrypt: true),
            'mime_type' => $mime,
            'original_filename' => 'scan résumé.jpg',
            'size' => strlen($contents),
            ...$attributes,
        ]);
    }

    public function test_the_preview_shows_the_file_inline_through_the_server_and_is_audited(): void
    {
        $member = $this->member();
        $document = $this->stored($member, 'image/jpeg', 'jpeg-bytes');

        $response = $this->actingAs($member)->get(route('member.license-documents.preview', $document))->assertOk();

        $this->assertSame('jpeg-bytes', $response->streamedContent());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // Our CSP and framing rules are not relaxed for it.
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));

        $this->assertSame(1, AuditLog::where('action', 'document_previewed')->where('user_id', $member->id)->where('resource_id', $document->id)->count());
        $this->assertStringNotContainsString('scan', AuditLog::where('action', 'document_previewed')->get()->toJson());
    }

    public function test_an_image_is_previewed_in_place_and_a_pdf_in_a_new_tab(): void
    {
        $member = $this->member();
        $image = $this->stored($member, 'image/png', 'png-bytes');
        $pdf = $this->stored($member, 'application/pdf', '%PDF-1.4 x');
        $preview = fn (LicenseDocument $document) => route('member.license-documents.preview', $document);

        $this->actingAs($member)->get(route('member.license-documents', ['document' => $image->id]))->assertOk()
            ->assertSee('<img class="ppx-docs__preview-image" src="'.$preview($image).'"', false);

        $this->get(route('member.license-documents', ['document' => $pdf->id]))->assertOk()
            ->assertSee('href="'.$preview($pdf).'" target="_blank" rel="noopener"', false)
            ->assertDontSee('<iframe', false)
            ->assertDontSee('<img class="ppx-docs__preview-image"', false);
    }

    public function test_large_files_offer_only_download(): void
    {
        config(['portal.member_area.preview_max_kilobytes' => 1]);
        $member = $this->member();
        $document = $this->stored($member, 'application/pdf', str_repeat('x', 2048));

        $this->actingAs($member)->get(route('member.license-documents', ['document' => $document->id]))->assertOk()
            ->assertSee('too large to preview')
            ->assertDontSee(route('member.license-documents.preview', $document), false)
            ->assertSee(route('member.documents.download', ['type' => 'license', 'document' => $document->id]), false);

        $this->get(route('member.license-documents.preview', $document))->assertNotFound();
        $this->assertSame(0, AuditLog::where('action', 'document_previewed')->count());
    }

    public function test_the_preview_is_closed_to_others_as_a_download_is(): void
    {
        $member = $this->member();
        $document = $this->stored($member, 'image/png', 'png-bytes');

        $this->get(route('member.license-documents.preview', $document))->assertRedirect();

        $this->actingAs($this->member())->get(route('member.license-documents.preview', $document))->assertForbidden();

        $principal = $this->agency(2)->principal()->first();
        $this->actingAs($principal)->get(route('member.license-documents.preview', $this->stored($principal, 'image/png', 'x')))->assertNotFound();

        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()->subMonth()])->save();
        $this->actingAs($member->fresh())->get(route('member.license-documents.preview', $document))->assertNotFound();

        $this->assertSame(0, AuditLog::where('action', 'document_previewed')->count());
    }

    public function test_after_an_upload_from_the_page_it_opens_that_document(): void
    {
        $member = $this->member();

        $response = $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(route('member.license-documents', ['document' => LicenseDocument::sole()->id]), $response->json('redirect'));
    }

    public function test_staff_see_the_upload_in_nova(): void
    {
        $member = $this->member();
        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM]);
        $document = LicenseDocument::sole();

        $this->actingAs($this->staff(Role::MembershipCoordinator))->getJson("/nova-api/license-documents/{$document->id}")->assertOk();
    }

    public function test_a_wrong_type_is_refused_by_its_content_whatever_it_is_called(): void
    {
        $member = $this->member();
        $this->actingAs($member)->from(route('member.license-documents.upload'));

        foreach ([
            UploadedFile::fake()->createWithContent('license.pdf', '<?php echo "not a pdf";'),
            UploadedFile::fake()->createWithContent('license.txt', 'plain text'),
            UploadedFile::fake()->createWithContent('license.tiff', "II*\0".str_repeat("\0", 64)),
        ] as $file) {
            $this->post(route('member.license-documents.store'), ['file' => $file, ...self::PAGE_FROM])
                ->assertRedirect(route('member.license-documents.upload'))
                ->assertSessionHasErrors(['file' => self::WRONG_FORMAT]);
        }

        $this->assertSame(0, LicenseDocument::count());
    }

    public function test_size_and_missing_fields_get_plain_messages(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        $this->post(route('member.license-documents.store'), ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'), ...self::PAGE_FROM])
            ->assertSessionHasErrors(['file' => self::TOO_LARGE]);
        $this->post(route('member.license-documents.store'), self::PAGE_FROM)
            ->assertSessionHasErrors(['file' => 'Choose a file to upload.']);
        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => ''])
            ->assertSessionHasErrors(['document_type' => 'Choose the document type.']);
        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'passport'])
            ->assertSessionHasErrors(['document_type' => 'Choose the document type from the list.']);

        $this->assertSame(0, LicenseDocument::count());
    }

    public function test_an_upload_too_large_for_php_gets_the_size_message(): void
    {
        $this->actingAs($this->member());
        $tooLarge = ['CONTENT_LENGTH' => (string) (1024 * 1024 * 1024 * 8)];

        $this->call('POST', route('member.license-documents.store'), [], [], [], $tooLarge)
            ->assertRedirect(route('member.license-documents.upload', ['too_large' => 1]));

        $this->call('POST', route('member.license-documents.store'), [], [], [], $tooLarge + ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)
            ->assertJson(['message' => self::TOO_LARGE]);

        $this->get(route('member.license-documents.upload', ['too_large' => 1]))->assertOk()
            ->assertSee(self::TOO_LARGE);
    }

    public function test_the_upload_page_shows_a_refused_file_in_its_row(): void
    {
        $this->actingAs($this->member())->from(route('member.license-documents.upload'))->followingRedirects()
            ->post(route('member.license-documents.store'), ['file' => UploadedFile::fake()->createWithContent('a.txt', 'text'), ...self::PAGE_FROM])
            ->assertOk()
            ->assertSee('is-rejected', false)
            ->assertSee(self::WRONG_FORMAT);
    }

    public function test_the_upload_page_promises_a_malware_scan_only_when_there_is_one(): void
    {
        $this->actingAs($this->member())->get(route('member.license-documents.upload'))->assertOk()
            ->assertSee('Your documents are secure')
            ->assertDontSee('scanned for malware');

        config(['portal.member_area.malware_scan' => true]);
        $this->get(route('member.license-documents.upload'))->assertSee('scanned for malware');
    }

    public function test_unavailable_storage_gets_a_plain_message_and_stores_nothing(): void
    {
        config(['filesystems.disks.azure' => ['driver' => 'azure-storage-blob', 'container' => 'portal-documents', 'endpoint' => 'https://storage.test']]);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $member = $this->member();

        $this->actingAs($member)->from(route('member.license-documents.upload'))
            ->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM])
            ->assertRedirect(route('member.license-documents.upload'))
            ->assertSessionHasErrors('file');

        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'temporarily unavailable') && ! str_contains($message, 'storage.test'));

        $this->assertSame(0, LicenseDocument::count());
    }

    public function test_a_failed_write_stores_no_record(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andThrow(new RuntimeException('Unable to write: secret/path'));
        Storage::set(DocumentVault::diskName(), $disk);

        $this->actingAs($this->member())->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertDontSee('secret/path');

        $this->assertSame(0, LicenseDocument::count());
    }

    // Withdrawal

    public function test_a_pending_document_is_withdrawn_after_a_confirmation_and_its_file_deleted(): void
    {
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Pending, ['original_filename' => 'jordan-secret.pdf']);
        $path = $document->stored_filename;

        $this->actingAs($member)->get(route('member.license-documents', ['document' => $document->id]))->assertOk()
            ->assertSee('Withdraw this document')
            ->assertDontSee('Withdraw document');

        $this->get(route('member.license-documents', ['document' => $document->id, 'withdraw' => 1]))->assertOk()
            ->assertSee('Withdraw this document?')
            ->assertSee('Withdraw document')
            ->assertSee('Keep it');

        $this->assertModelExists($document);

        $this->delete(route('member.license-documents.destroy', $document->id))
            ->assertRedirect(route('member.license-documents'))
            ->assertSessionHas('status', 'Document withdrawn.');

        $this->assertModelMissing($document);
        Storage::disk(config('portal.documents_disk'))->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_deleted', 'user_id' => $member->id, 'resource_id' => $document->id]);
    }

    public function test_reviewed_documents_cannot_be_withdrawn(): void
    {
        $member = $this->member();

        foreach ([VerificationStatus::Approved, VerificationStatus::Rejected] as $status) {
            $document = $this->document($member, $status);

            $this->actingAs($member)->get(route('member.license-documents', ['document' => $document->id, 'withdraw' => 1]))->assertOk()
                ->assertDontSee('Withdraw this document')
                ->assertDontSee('Withdraw document');

            $this->delete(route('member.license-documents.destroy', $document->id))->assertForbidden();
            $this->assertModelExists($document);
            Storage::disk(config('portal.documents_disk'))->assertExists($document->stored_filename);
        }
    }

    public function test_someone_elses_pending_document_cannot_be_withdrawn(): void
    {
        $other = $this->document($this->member(), VerificationStatus::Pending);

        $this->actingAs($this->member())->delete(route('member.license-documents.destroy', $other->id))->assertForbidden();

        $this->assertModelExists($other);
    }

    public function test_unavailable_storage_leaves_the_document_in_place(): void
    {
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Pending);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->andReturn(false);
        Storage::set(DocumentVault::diskName(), $disk);

        $this->actingAs($member)->delete(route('member.license-documents.destroy', $document->id))
            ->assertRedirect(route('member.license-documents', ['document' => $document->id]))
            ->assertSessionHas('document_error');

        $this->assertModelExists($document);
    }

    // Who may

    public function test_agency_people_have_no_page_and_no_menu_item(): void
    {
        foreach ([$this->agency(2)->principal()->first(), $this->agencyPerson()] as $person) {
            $document = $this->document($person, VerificationStatus::Pending);

            $this->actingAs($person);
            $this->get(route('member.license-documents'))->assertNotFound();
            $this->get(route('member.license-documents.upload'))->assertNotFound();
            $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM])->assertForbidden();
            $this->delete(route('member.license-documents.destroy', $document->id))->assertNotFound();
            $this->get(route('member.dashboard'))->assertOk()->assertDontSee(route('member.license-documents'), false);
            $this->assertModelExists($document);
        }
    }

    public function test_a_canceled_membership_closes_the_page_as_in_nova(): void
    {
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Pending);
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()->subMonth()])->save();
        $member = $member->fresh();

        $this->actingAs($member);
        $this->get(route('member.license-documents'))->assertNotFound();
        $this->get(route('member.license-documents.upload'))->assertNotFound();
        $this->post(route('member.license-documents.store'), ['file' => $this->pdf(), ...self::PAGE_FROM])->assertForbidden();
        $this->delete(route('member.license-documents.destroy', $document->id))->assertNotFound();
        $this->get(route('member.documents.download', ['type' => 'license', 'document' => $document->id]))->assertForbidden();
        $this->get('/nova/resources/license-documents')->assertRedirect(route('member.dashboard'));
    }

    public function test_the_page_is_closed_while_the_member_area_is_off(): void
    {
        config(['portal.member_area.enabled' => false]);

        $this->actingAs($this->member())->get('/member/license-documents')->assertNotFound();
    }

    // Audit and notifications

    public function test_audit_entries_hold_no_file_names(): void
    {
        $member = $this->member();

        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => $this->pdf('jordan-holloway-license.pdf'), ...self::PAGE_FROM]);
        $this->delete(route('member.license-documents.destroy', LicenseDocument::sole()->id));

        $this->assertSame(1, AuditLog::where('action', 'document_uploaded')->count());
        $this->assertSame(1, AuditLog::where('action', 'document_deleted')->count());
        $this->assertStringNotContainsString('jordan-holloway', AuditLog::query()->get()->toJson());
    }

    public function test_staff_write_the_reason_apart_from_their_notes_and_approval_clears_it(): void
    {
        $document = $this->document($this->member(), VerificationStatus::Pending);
        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $this->postJson('/nova-api/license-documents/action?action=reject-license-document', ['resources' => (string) $document->id])
            ->assertJsonValidationErrors('rejection_reason');

        $this->postJson('/nova-api/license-documents/action?action=reject-license-document', [
            'resources' => (string) $document->id,
            'rejection_reason' => 'Expired copy.',
            'admin_notes' => 'Internal.',
        ])->assertOk();

        $document->refresh();
        $this->assertSame('Expired copy.', $document->rejection_reason);
        $this->assertSame('Internal.', $document->admin_notes);
        $this->assertStringNotContainsString('Expired', (string) $document->getRawOriginal('rejection_reason'));
        $this->assertStringNotContainsString('Expired copy.', AuditLog::query()->get()->toJson());

        $this->postJson('/nova-api/license-documents/action?action=verify-license-document', ['resources' => (string) $document->id])->assertOk();
        $this->assertNull($document->fresh()->rejection_reason);
    }

    public function test_members_cannot_read_internal_notes_through_novas_api(): void
    {
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Rejected, ['rejection_reason' => 'Please re-upload.', 'admin_notes' => 'Internal remark.']);

        $this->actingAs($member)->getJson("/nova-api/license-documents/{$document->id}")->assertOk()
            ->assertSee('Please re-upload.')
            ->assertDontSee('Internal remark.');
    }

    public function test_the_upload_page_lists_the_latest_five_documents_with_their_decisions(): void
    {
        $member = $this->member();
        $this->document($member, VerificationStatus::Approved, ['original_filename' => 'oldest.pdf', 'uploaded_at' => '2026-01-01 10:00:00']);

        foreach ([2, 3, 4] as $day) {
            $this->document($member, VerificationStatus::Approved, ['original_filename' => "middle-{$day}.pdf", 'uploaded_at' => "2026-02-0{$day} 10:00:00"]);
        }

        $this->document($member, VerificationStatus::Pending, ['original_filename' => 'waiting.pdf', 'uploaded_at' => '2026-03-01 10:00:00']);
        $rejected = $this->document($member, VerificationStatus::Rejected, ['original_filename' => 'blurry.pdf', 'uploaded_at' => '2026-03-02 10:00:00', 'rejection_reason' => 'The scan is unreadable.', 'admin_notes' => 'Internal remark.']);
        $this->document($this->member(), VerificationStatus::Pending, ['original_filename' => 'not-yours.pdf']);

        $this->actingAs($member)->get(route('member.license-documents.upload'))->assertOk()
            ->assertSee('Previously uploaded')
            ->assertSee('href="'.route('member.license-documents').'"', false)
            ->assertSee(route('member.license-documents', ['document' => $rejected->id]), false)
            ->assertSeeInOrder(['blurry.pdf', 'Rejected', '2 Mar 2026', 'Reason: The scan is unreadable.', 'waiting.pdf', 'Pending review', 'middle-4.pdf', 'Approved'])
            ->assertSee('#clock', false)->assertSee('#check', false)->assertSee('#close', false)
            ->assertSee('ppx-badge--warning', false)->assertSee('ppx-badge--success', false)->assertSee('ppx-badge--danger', false)
            ->assertDontSee('oldest.pdf')
            ->assertDontSee('not-yours.pdf')
            ->assertDontSee('Internal remark.');
    }

    public function test_the_upload_page_has_no_earlier_section_before_the_first_document(): void
    {
        $this->actingAs($this->member())->get(route('member.license-documents.upload'))->assertOk()
            ->assertDontSee('Previously uploaded');
    }

    public function test_a_rejection_reaches_the_bell_with_its_reason_and_the_mailbox_with_a_link_to_upload_again(): void
    {
        config(['mail.default' => 'array']);
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Pending, ['original_filename' => 'jordan-secret.pdf', 'license_number' => 'OH-555']);

        $this->actingAs($this->staff(Role::MembershipCoordinator))->postJson('/nova-api/license-documents/action?action=reject-license-document', [
            'resources' => (string) $document->id,
            'rejection_reason' => "The scan isn't readable.",
            'admin_notes' => 'Internal remark.',
        ])->assertOk();

        $notification = $member->notifications()->sole();
        $this->assertSame(['document_id' => $document->id, 'status' => 'rejected'], $notification->data);

        $item = NotificationFeed::latest($member)->sole();
        $this->assertSame('License document rejected', $item['title']);
        $this->assertSame("Reason: The scan isn't readable.", $item['detail']);

        $this->actingAs($member)->get(route('member.profile'))->assertOk()
            ->assertSee('ppx-notify__item-detail', false)
            ->assertSee("Reason: The scan isn't readable.");

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $message = $sent->sole()->getOriginalMessage();
        $this->assertSame('Action needed: your ProPerX Alliance document was not approved', $message->getSubject());
        $this->assertSame($member->email, $message->getTo()[0]->getAddress());

        $html = html_entity_decode((string) $message->getHtmlBody(), ENT_QUOTES);
        $this->assertStringContainsString("Reason: The scan isn't readable.", $html);
        $this->assertStringContainsString(route('member.license-documents.upload'), $html);
        $this->assertStringContainsString('src="cid:properx-logo"', (string) $message->getHtmlBody());

        foreach (['jordan-secret', 'OH-555', 'Internal remark.', $member->email, $member->memberProfile->last_name] as $private) {
            $this->assertStringNotContainsString($private, $html.$message->getSubject());
        }
    }

    public function test_an_approval_reaches_the_bell_and_the_mailbox_with_a_link_to_the_document(): void
    {
        config(['mail.default' => 'array']);
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Pending);

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/license-documents/action?action=verify-license-document', ['resources' => (string) $document->id])
            ->assertOk();

        $item = NotificationFeed::latest($member)->sole();
        $this->assertSame('License document approved', $item['title']);
        $this->assertNull($item['detail']);

        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Your ProPerX Alliance document has been approved', $message->getSubject());
        $this->assertStringContainsString(e(route('member.license-documents', ['document' => $document->id])), (string) $message->getHtmlBody());
        $this->assertStringNotContainsString('Reason:', (string) $message->getHtmlBody());
    }

    public function test_the_queued_email_carries_ids_only_and_is_dropped_once_the_decision_has_changed(): void
    {
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Rejected, ['rejection_reason' => 'The scan is unreadable.']);
        $email = LicenseDocumentDecision::for($document);

        $this->assertStringNotContainsString('unreadable', serialize($email));
        $this->assertTrue($email->shouldSend($member, 'mail'));

        $document->forceFill(['verification_status' => VerificationStatus::Approved, 'rejection_reason' => null])->saveQuietly();
        $this->assertFalse($email->shouldSend($member, 'mail'));

        $document->delete();
        $this->assertFalse($email->shouldSend($member, 'mail'));
    }

    public function test_the_emails_links_lead_somewhere_open_to_a_restricted_member(): void
    {
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Rejected, ['rejection_reason' => 'Please re-upload.']);
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled])->saveQuietly();

        $mail = LicenseDocumentDecision::for($document)->toMail($member->fresh());

        $this->assertSame(route('member.dashboard'), $mail->actionUrl);
    }

    public function test_a_review_notification_opens_the_document(): void
    {
        $member = $this->member();
        $document = $this->document($member, VerificationStatus::Pending);
        $member->notify(LicenseDocumentReviewed::for($document));
        $notification = $member->notifications()->sole();

        $this->actingAs($member)->get(route('member.notifications.open', $notification->id))
            ->assertRedirect(route('member.license-documents', ['document' => $document->id]));
    }
}
