<?php

namespace Tests\Feature\Member;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Livewire\Member\Dashboard;
use App\Member\DocumentList;
use App\Models\AuditLog;
use App\Models\TaxDocument;
use App\Models\User;
use App\Notifications\Member\TaxDocumentAvailable;
use App\Services\DocumentDownloader;
use App\Services\Documents\DocumentVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Tax documents on the dashboard's Documents tab: only the member's own, downloads tracked
 * (Spec "download tracking"), and the "tax document available" notification leads there.
 */
class TaxDocumentsTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    private function document(User $member, string $type = '1099-NEC', string $year = '2025'): TaxDocument
    {
        return TaxDocument::factory()->for($member)->create([
            'document_type' => $type,
            'tax_year' => $year,
            'stored_filename' => app(DocumentVault::class)->put(TaxDocument::DIRECTORY, 'pdf', '%PDF-1.4 tax', encrypt: true),
            'original_filename' => 'tax.pdf',
            'downloaded_at' => null,
        ]);
    }

    private function download(TaxDocument $document): void
    {
        $signed = $this->get(route('member.documents.download', ['type' => 'tax', 'document' => $document->id]))->assertRedirect()->headers->get('Location');

        $this->assertSame('%PDF-1.4 tax', $this->get($signed)->assertOk()->streamedContent());
    }

    public function test_the_filter_shows_only_the_members_own(): void
    {
        $member = $this->member();
        $own = $this->document($member, '1099-NEC', '2025');
        $this->document($this->member(), 'W-9', '2019');

        $rows = DocumentList::for($member, 'tax');

        $this->assertSame(['1099-NEC'], $rows->pluck('name')->all());
        $this->assertSame(route('member.documents.download', ['type' => 'tax', 'document' => $own->id]), $rows->first()['url']);

        Livewire::actingAs($member)->withQueryParams(['tab' => 'documents', 'filter' => 'tax'])->test(Dashboard::class)
            ->assertSee('1099-NEC')
            ->assertSee('Tax year 2025')
            ->assertDontSee('W-9')
            ->assertDontSee('Tax year 2019')
            ->assertDontSee('Membership certificate');
    }

    public function test_nobody_downloads_someone_elses(): void
    {
        $other = $this->document($this->member());

        $this->actingAs($this->member())
            ->get(route('member.documents.download', ['type' => 'tax', 'document' => $other->id]))
            ->assertForbidden();

        $this->assertNull($other->fresh()->downloaded_at);
        $this->assertSame(0, AuditLog::where('action', 'document_downloaded')->count());
    }

    public function test_the_members_download_is_tracked_and_audited(): void
    {
        $member = $this->member();
        $document = $this->document($member);
        $this->travelTo(now()->startOfSecond());

        $this->actingAs($member);
        $this->download($document);

        $this->assertEquals(now(), $document->fresh()->downloaded_at);
        $this->assertSame(1, AuditLog::where('action', 'document_downloaded')->where('user_id', $member->id)->where('resource_id', $document->id)->count());
    }

    public function test_staff_opening_it_is_not_counted_as_the_member_retrieving_it(): void
    {
        $document = $this->document($this->member());

        $this->actingAs($this->staff(Role::ComplianceSpecialist));
        $this->assertNotNull($this->get(route('documents.download', ['type' => 'tax', 'document' => $document->id])));
        $signed = DocumentDownloader::signedUrl($document);
        $this->get($signed)->assertOk();

        $this->assertNull($document->fresh()->downloaded_at);
    }

    public function test_staff_see_the_tracking_in_nova(): void
    {
        $member = $this->member();
        $document = $this->document($member);
        $this->actingAs($member);
        $this->download($document);

        $this->actingAs($this->staff(Role::ComplianceSpecialist))
            ->getJson("/nova-api/tax-documents/{$document->id}")->assertOk()
            ->assertSee('downloaded_at');
    }

    public function test_the_notification_leads_to_the_tax_filter_and_marks_it_read(): void
    {
        $member = $this->member();
        $document = $this->document($member); // DocumentObserver notifies the member.
        $notification = $member->notifications()->where('type', TaxDocumentAvailable::class)->sole();
        $this->assertSame(['document_id' => $document->id], array_intersect_key($notification->data, ['document_id' => true]));

        $target = route('member.dashboard', ['tab' => 'documents', 'filter' => 'tax']);

        $this->actingAs($member)->get(route('member.notifications.open', $notification->id))->assertRedirect($target);
        $this->assertNotNull($notification->fresh()->read_at);

        $this->get($target)->assertOk()->assertSee('1099-NEC');
    }

    public function test_tax_documents_stay_open_with_a_canceled_membership(): void
    {
        $member = $this->member();
        $document = $this->document($member);
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()->subMonth()])->save();

        $this->actingAs($member->fresh());
        $this->download($document);

        $this->assertNotNull($document->fresh()->downloaded_at);
    }
}
