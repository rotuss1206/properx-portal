<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\AuditLog;
use App\Models\IntakeSubmission;
use App\Models\LicenseDocument;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use Database\Seeders\Support\DemoFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * POST /api/intake/license-copy: the license copy uploaded with a paid application on properx.org
 * becomes the member's License Document, pending review.
 */
class IntakeLicenseCopyTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    private const SECRET = 'test-intake-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->apiService();
        config(['portal.intake.enabled' => true, 'portal.intake.secret' => self::SECRET, 'portal.intake.send_invite' => false]);
    }

    /**
     * @param  array<string, mixed>|string  $payload
     */
    private function signed(string $path, array|string $payload, string $secret = self::SECRET): TestResponse
    {
        $body = is_string($payload) ? $payload : json_encode($payload);
        $timestamp = now()->getTimestamp();

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ], $body);
    }

    private function apply(string $entryId = '201', string $email = 'copy.applicant@example.test'): void
    {
        $this->signed('/api/intake/individual', [
            'entry_id' => $entryId, 'email' => $email, 'first_name' => 'Avery', 'last_name' => 'Stone',
            'license_number' => 'OH-445566', 'license_state' => 'Ohio', 'license_types' => ['Life'],
            'license_expires_at' => today()->addYear()->toDateString(),
        ])->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function copy(string $entryId = '201', ?string $contents = null, string $name = 'My License.pdf'): array
    {
        return ['entry_id' => $entryId, 'file_name' => $name, 'content' => base64_encode($contents ?? DemoFile::pdf('License'))];
    }

    public function test_the_copy_becomes_an_encrypted_license_document_pending_review_once(): void
    {
        $this->apply();

        $this->signed('/api/intake/license-copy', $this->copy())->assertOk()->assertJsonPath('outcome', 'stored');

        $user = User::where('email', 'copy.applicant@example.test')->firstOrFail();
        $document = LicenseDocument::query()->sole();
        $this->assertSame($user->getKey(), $document->user_id);
        $this->assertSame(VerificationStatus::Pending, $document->verification_status);
        $this->assertSame(['My License.pdf', 'application/pdf', 'resident', 'OH-445566', 'OH'], [$document->original_filename, $document->mime_type, $document->document_type, $document->license_number, $document->license_state]);
        $this->assertTrue(DocumentVault::isEncrypted($document->stored_filename));
        $this->assertStringNotContainsString('%PDF', Storage::disk('azure')->get($document->stored_filename));
        $this->assertSame($document->getKey(), IntakeSubmission::query()->sole()->license_document_id);

        // Audited as an upload, without the file's name.
        $audit = AuditLog::query()->where('resource_type', $document->getMorphClass())->where('resource_id', $document->getKey())->sole();
        $this->assertStringNotContainsString('My License', json_encode($audit->details));

        // A repeated delivery stores nothing more.
        $this->signed('/api/intake/license-copy', $this->copy())->assertOk()->assertJsonPath('outcome', 'already_stored');
        $this->assertSame(1, LicenseDocument::query()->count());
        $this->assertCount(1, Storage::disk('azure')->allFiles(LicenseDocument::DIRECTORY));
    }

    public function test_before_the_application_arrives_the_site_is_asked_to_try_again(): void
    {
        $this->signed('/api/intake/license-copy', $this->copy('999'))->assertConflict()->assertJsonPath('outcome', 'intake_pending');

        $this->assertSame(0, LicenseDocument::query()->count());
        $this->assertSame([], Storage::disk('azure')->allFiles());
    }

    public function test_only_pdf_jpg_or_png_up_to_10_mb_by_their_bytes(): void
    {
        $this->apply();

        $this->signed('/api/intake/license-copy', $this->copy(contents: 'MZ executable, named .pdf'))->assertUnprocessable()->assertJsonPath('fields', ['content']);
        $this->signed('/api/intake/license-copy', $this->copy(contents: '%PDF-1.4 '.str_repeat('x', 10 * 1024 * 1024)))->assertUnprocessable();
        $this->signed('/api/intake/license-copy', ['entry_id' => '201', 'file_name' => 'a.pdf', 'content' => 'not base64!'])->assertUnprocessable();

        // A PNG named .pdf keeps a name that matches its contents.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->signed('/api/intake/license-copy', $this->copy(contents: $png, name: 'C:\\fakepath\\license.pdf'))->assertOk();
        $this->assertSame('license.pdf.png', LicenseDocument::query()->sole()->original_filename);
    }

    public function test_a_kept_out_applicant_gets_no_document(): void
    {
        $this->staff(Role::MembershipCoordinator)->forceFill(['email' => 'staff.person@example.test'])->save();
        $this->apply('301', 'staff.person@example.test');
        $this->assertSame('conflict', IntakeSubmission::query()->sole()->outcome);

        $this->signed('/api/intake/license-copy', $this->copy('301'))->assertOk()->assertJsonPath('outcome', 'skipped');
        $this->assertSame(0, LicenseDocument::query()->count());
    }

    public function test_the_signature_is_checked(): void
    {
        $this->apply();

        $this->signed('/api/intake/license-copy', $this->copy(), 'wrong-secret')->assertUnauthorized();
        $this->assertSame(0, LicenseDocument::query()->count());
    }
}
