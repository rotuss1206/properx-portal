<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Services\Documents\DocumentVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Audit trail and last login tracking (Spec 8).
 */
class AuditLogTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake(config('portal.documents_disk'));
    }

    public function test_login_updates_last_login_and_is_audited(): void
    {
        $member = $this->member();
        $this->assertNull($member->last_login_at);

        $this->postJson('/nova/login', ['email' => $member->email, 'password' => 'password'], ['User-Agent' => 'PHPUnit'])
            ->assertOk();

        $this->assertAuthenticatedAs($member);
        $this->assertNotNull($member->fresh()->last_login_at);

        $log = AuditLog::where('action', 'login')->sole();
        $this->assertSame($member->id, $log->user_id);
        $this->assertSame('PHPUnit', $log->user_agent);
        $this->assertNotNull($log->ip_address);
    }

    public function test_failed_login_is_not_recorded_as_login(): void
    {
        $member = $this->member();

        $this->postJson('/nova/login', ['email' => $member->email, 'password' => 'wrong'])->assertUnprocessable();

        $this->assertNull($member->fresh()->last_login_at);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'login']);
    }

    public function test_logout_is_audited(): void
    {
        $member = $this->member();

        $this->actingAs($member)->post('/nova/logout');

        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'user_id' => $member->id]);
    }

    public function test_document_review_is_audited(): void
    {
        $coordinator = $this->staff(Role::MembershipCoordinator);
        $document = LicenseDocument::factory()->pending()->for($this->member())->create();

        $this->actingAs($coordinator)
            ->postJson('/nova-api/license-documents/action?action=verify-license-document', ['resources' => (string) $document->id])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_verified',
            'user_id' => $coordinator->id,
            'resource_type' => $document->getMorphClass(),
            'resource_id' => $document->id,
        ]);
    }

    public function test_document_downloads_are_audited(): void
    {
        $member = $this->member();
        $vault = app(DocumentVault::class);

        $license = LicenseDocument::factory()->for($member)->create([
            'mime_type' => 'application/pdf',
            'stored_filename' => $vault->put(LicenseDocument::DIRECTORY, 'pdf', '%PDF-1.4 license', encrypt: true),
        ]);
        $tax = TaxDocument::factory()->for($member)->create([
            'downloaded_at' => null,
            'stored_filename' => $vault->put(TaxDocument::DIRECTORY, 'pdf', '%PDF-1.4 tax', encrypt: true),
        ]);
        $library = LibraryDocument::factory()->published()->create([
            'file_path' => $vault->put(LibraryDocument::DIRECTORY, 'pdf', '%PDF-1.4 library', encrypt: false),
        ]);

        $this->actingAs($member);

        // Encrypted documents are decrypted and streamed by the app.
        $response = $this->get("/nova-api/license-documents/{$license->id}/download/stored_filename")
            ->assertOk()
            ->assertDownload($license->original_filename);
        $this->assertSame('%PDF-1.4 license', $response->streamedContent());

        $this->assertSame('%PDF-1.4 tax', $this->get("/nova-api/tax-documents/{$tax->id}/download/stored_filename")->streamedContent());

        // Unencrypted library files are handed out as short-lived signed links.
        $this->get("/nova-api/library-documents/{$library->id}/download/file_path")->assertRedirect();

        $this->assertSame(3, AuditLog::where('action', 'document_downloaded')->where('user_id', $member->id)->count());
        $this->assertNotNull($tax->fresh()->downloaded_at);
    }

    public function test_other_members_documents_cannot_be_downloaded(): void
    {
        $document = LicenseDocument::factory()->for($this->member())->create();
        Storage::disk(config('portal.documents_disk'))->put($document->stored_filename, 'x');

        $this->actingAs($this->member())
            ->get("/nova-api/license-documents/{$document->id}/download/stored_filename")
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'document_downloaded']);
    }
}
