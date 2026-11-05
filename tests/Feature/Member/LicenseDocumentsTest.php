<?php

namespace Tests\Feature\Member;

use App\Enums\LicenseVerification;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use App\Models\User;
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

    private const PAGE_FROM = ['from' => 'page', 'document_type' => 'resident'];

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
            ->assertSee('Pending review')->assertSee('Approved')->assertSee('Rejected');
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

        $this->actingAs($member)->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'non_resident', 'from' => 'page'])
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
                ->assertSessionHasErrors(['file' => 'Upload a PDF, JPG or PNG file.']);
        }

        $this->assertSame(0, LicenseDocument::count());
    }

    public function test_size_and_missing_fields_get_plain_messages(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        $this->post(route('member.license-documents.store'), ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'), ...self::PAGE_FROM])
            ->assertSessionHasErrors(['file' => 'The file may not be larger than 10 MB.']);
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
            ->assertJson(['message' => 'The file may not be larger than 10 MB.']);

        $this->get(route('member.license-documents.upload', ['too_large' => 1]))->assertOk()
            ->assertSee('The file may not be larger than 10 MB.');
    }

    public function test_the_upload_page_shows_a_refused_file_in_its_row(): void
    {
        $this->actingAs($this->member())->from(route('member.license-documents.upload'))->followingRedirects()
            ->post(route('member.license-documents.store'), ['file' => UploadedFile::fake()->createWithContent('a.txt', 'text'), ...self::PAGE_FROM])
            ->assertOk()
            ->assertSee('data-state="error"', false)
            ->assertSee('Upload a PDF, JPG or PNG file.');
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
