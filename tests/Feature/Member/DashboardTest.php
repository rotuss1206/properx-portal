<?php

namespace Tests\Feature\Member;

use App\Enums\AgencyStatus;
use App\Enums\LicenseVerification;
use App\Enums\PaymentStatus;
use App\Enums\PersonType;
use App\Enums\RateType;
use App\Enums\Role;
use App\Livewire\Member\Dashboard;
use App\Member\Overview;
use App\Models\AuditLog;
use App\Models\LicenseDocument;
use App\Models\StripeEvent;
use App\Models\TaxDocument;
use App\Models\User;
use App\Notifications\Member\LicenseDocumentReviewed;
use App\Notifications\Member\RenewalDue;
use App\Notifications\Member\TaxDocumentAvailable;
use App\Services\Certificates\CertificateGeneratorService;
use Database\Seeders\Support\DemoFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Member dashboard (docs/specs/member-area.md, stage 2): blocks per kind of member, own data
 * only, in-app notifications from events, the license upload and the activity feed.
 */
class DashboardTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    // Blocks per kind of member

    public function test_individual_members_see_their_membership_license_certificate_and_the_upload(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill([
            'paid_through_at' => today()->addDays(76),
            'payment_status' => PaymentStatus::Active,
            'license_verification' => LicenseVerification::Verified,
        ])->save();

        $this->actingAs($member)->get('/member')->assertOk()
            ->assertSee('Renews '.today()->addDays(76)->format('j M Y'))
            ->assertSee('NPN '.$member->memberProfile->npn)
            ->assertSee('Verified')
            ->assertSee(route('certificates.open', $member->memberProfile), false)
            ->assertSee('data-ppx-upload', false)
            ->assertDontSee('ppx-agency-line', false)
            ->assertDontSee('Founding member');
    }

    public function test_agency_people_see_the_agency_line_and_no_upload(): void
    {
        $agency = $this->agency(2, ['rate_type' => RateType::Founding, 'status' => AgencyStatus::Active, 'paid_through_at' => today()->addDays(40)]);
        $principal = $agency->principal()->first();
        $person = $agency->members()->where('user_id', '!=', $principal->id)->first()->user;

        foreach ([$principal, $person] as $user) {
            $this->actingAs($user)->get('/member')->assertOk()
                ->assertSee('Agency: <strong>'.e($agency->legal_name).'</strong>', false)
                ->assertSee('principal: <strong>'.e($principal->memberProfile->fullName()).'</strong>', false)
                ->assertSee('Founding member')
                ->assertSee('Renews '.today()->addDays(40)->format('j M Y'))
                ->assertSee('License verification')
                ->assertDontSee('data-ppx-upload', false);
        }
    }

    public function test_agency_employees_hold_no_license(): void
    {
        $agency = $this->agency(2);
        $employee = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first();
        $employee->forceFill(['person_type' => PersonType::AgencyEmployee])->save();

        $this->assertNull($employee->fresh()->license_verification);

        $this->actingAs($employee->user)->get('/member')->assertOk()
            ->assertSee('Not applicable')
            ->assertDontSee('data-ppx-upload', false);
    }

    public function test_licensed_people_start_pending_review(): void
    {
        $this->assertSame(LicenseVerification::PendingReview, $this->member()->memberProfile->license_verification);
    }

    public function test_the_benefits_tab_shows_the_configured_catalogue_as_available(): void
    {
        $member = $this->member();

        $html = Livewire::actingAs($member)->test(Dashboard::class)->call('show', 'benefits')
            ->assertSeeInOrder(['Group health insurance', 'Group dental', 'Group vision', 'Group pet insurance', 'CRM for agents', 'Business discounts'])
            ->assertSee('Nationwide PPO group health plan')
            ->assertSee('benefit-pet', false)
            ->assertDontSee('Enrolled')
            ->assertDontSee('Pending')
            ->html();

        $this->assertSame(6, substr_count($html, '>Available</span>'));

        // The client edits the list in config/portal.php; an empty list hides the tab.
        config(['portal.member_area.benefits' => [['title' => 'Travel discounts', 'description' => 'Hotels and car rentals.', 'icon' => 'benefit-discounts', 'url' => 'https://example.test/travel']]]);
        Livewire::actingAs($member)->test(Dashboard::class)->call('show', 'benefits')
            ->assertSee('Travel discounts')->assertSee('Learn more')->assertDontSee('Group dental');

        config(['portal.member_area.benefits' => []]);
        Livewire::actingAs($member)->test(Dashboard::class)->assertDontSee('Benefits');
    }

    // Own data only

    public function test_documents_and_downloads_are_the_members_own(): void
    {
        $member = $this->member();
        $other = $this->member();
        $own = TaxDocument::factory()->for($member)->create(['document_type' => '1099-NEC', 'tax_year' => 2025]);
        $foreign = TaxDocument::factory()->for($other)->create(['document_type' => 'W-9', 'tax_year' => 2024]);

        Livewire::actingAs($member)->test(Dashboard::class)
            ->call('show', 'documents')
            ->assertSee('1099-NEC')
            ->assertDontSee('W-9')
            ->call('filterBy', 'license')
            ->assertDontSee('1099-NEC');

        $this->actingAs($member)->get(route('member.documents.download', ['type' => 'tax', 'document' => $own->id]))->assertRedirect();
        $this->get(route('member.documents.download', ['type' => 'tax', 'document' => $foreign->id]))->assertForbidden();
    }

    public function test_activity_shows_own_events_in_plain_words_and_payments_with_amounts(): void
    {
        $member = $this->member();
        $other = $this->member();
        $profile = $member->memberProfile;

        AuditLog::factory()->create(['user_id' => $member->id, 'action' => 'login']);
        AuditLog::factory()->create(['user_id' => $member->id, 'action' => 'logout']);
        AuditLog::factory()->create(['user_id' => $other->id, 'action' => 'certificate_generated']);
        AuditLog::factory()->create(['user_id' => null, 'action' => 'payment_status_changed', 'resource_type' => $profile->getMorphClass(), 'resource_id' => $profile->id, 'details' => ['from' => 'past_due', 'to' => 'active']]);
        StripeEvent::create(['id' => 'evt_test_paid', 'type' => 'invoice.paid', 'member_profile_id' => $profile->id, 'outcome' => 'updated', 'amount' => 19900, 'currency' => 'USD', 'plan_name' => 'Annual membership']);

        Livewire::actingAs($member)->test(Dashboard::class)
            ->call('show', 'activity')
            ->assertDontSee('Signed in') // sign-ins and sign-outs are not shown
            ->assertDontSee('Signed out')
            ->assertSee('Membership status updated')
            ->assertSee('Past due → Active')
            ->assertSee('Membership payment processed')
            ->assertSee('Annual membership · $199.00')
            ->assertDontSee('Membership certificate generated') // the other member's
            ->assertDontSee($member->email);
    }

    public function test_the_timeline_places_points_by_date_with_room_for_labels(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 24));
        $member = $this->member();
        $member->forceFill(['created_at' => now()->setDate(2019, 4, 1)])->save();
        $member->memberProfile->forceFill(['paid_through_at' => now()->setDate(2027, 3, 12)])->save();

        $timeline = Overview::for($member->fresh())->timeline();
        $positions = array_column($timeline['points'], 'pos', 'key');

        // Joined 2019 → renewed Mar 2026 → today Jun 2026 → next Mar 2027, the late points
        // pushed apart so their labels do not overlap.
        $this->assertSame(['joined', 'renewed', 'today', 'next'], array_keys($positions));
        $this->assertSame(0, $positions['joined']);
        $this->assertSame(100, $positions['next']);
        $this->assertGreaterThanOrEqual(Overview::TIMELINE_MIN_GAP, $positions['today'] - $positions['renewed']);
        $this->assertGreaterThanOrEqual(Overview::TIMELINE_MIN_GAP, $positions['next'] - $positions['today']);
        $this->assertGreaterThan(40, $positions['renewed']); // most of the track is the years before
        $this->assertSame(0, $positions['renewed'] % 5);
        $this->assertSame('2026 – 2027', $timeline['cycle']);

        $this->actingAs($member)->get('/member')
            ->assertSee('ppx-timeline__point is-done ppx-l-'.$positions['renewed'], false)
            ->assertSee('ppx-timeline__point is-next ppx-l-100 is-end', false);
    }

    public function test_document_rows_show_a_type_and_a_safe_caption(): void
    {
        $member = $this->member();
        LicenseDocument::factory()->for($member)->create(['document_type' => 'resident', 'original_filename' => 'jane-doe-license-OH-4471.pdf', 'uploaded_at' => now()->setDate(2026, 10, 2)]);
        TaxDocument::factory()->for($member)->create(['document_type' => '1099-NEC', 'tax_year' => 2025]);

        Livewire::actingAs($member)->test(Dashboard::class)->call('show', 'documents')
            ->assertSee('Resident license')
            ->assertSee('Uploaded Oct 2026')
            ->assertSee('Tax year 2025')
            ->assertSee(now()->year.' certificate')
            ->assertDontSee('jane-doe-license')
            ->assertDontSee((string) $member->memberProfile->license_number);
    }

    public function test_activity_shows_profile_changes_by_section_on_coloured_tiles(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        AuditLog::factory()->create(['user_id' => $member->id, 'action' => 'profile_updated', 'resource_type' => $profile->getMorphClass(), 'resource_id' => $profile->id, 'details' => ['fields' => ['phone', 'address']]]);
        AuditLog::factory()->create(['user_id' => $member->id, 'action' => 'certificate_generated', 'resource_type' => $profile->getMorphClass(), 'resource_id' => $profile->id]);

        Livewire::actingAs($member)->test(Dashboard::class)->call('show', 'activity')
            ->assertSee('Profile updated')
            ->assertSee('Contact information')
            ->assertSee('ppx-activity__icon--blue', false)
            ->assertSee('ppx-activity__icon--yellow', false)
            ->assertDontSee((string) $profile->phone);
    }

    public function test_agency_people_get_a_certificate_without_license_data(): void
    {
        Storage::fake(config('portal.certificates.disk'));
        $agency = $this->agency(2, ['paid_through_at' => today()->addMonths(8)]);
        $employee = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first();
        $employee->forceFill(['person_type' => PersonType::AgencyEmployee, 'npn' => null, 'license_expires_at' => null, 'license_number' => null])->save();
        $employee->refresh();

        $certificate = Overview::for($employee->user)->certificate();
        $this->assertTrue($certificate['ready']);

        $this->actingAs($employee->user)->get(CertificateGeneratorService::downloadUrl($employee))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(today()->addMonths(8)->toDateString(), CertificateGeneratorService::validThrough($employee)->toDateString());

        $this->get(route('certificates.verify', $employee->certificate_code))->assertOk()
            ->assertSee('Valid certificate')
            ->assertSee(today()->addMonths(8)->toFormattedDateString());

        // Individual members still need a license expiry date.
        $individual = $this->member();
        $individual->memberProfile->forceFill(['license_expires_at' => null])->save();
        $this->assertFalse(Overview::for($individual->fresh())->certificate()['ready']);
    }

    // Notifications

    public function test_reviewing_a_license_document_notifies_its_owner(): void
    {
        $member = $this->member();
        $document = LicenseDocument::factory()->pending()->for($member)->create();

        $this->actingAs($this->staff(Role::MembershipCoordinator))
            ->postJson('/nova-api/license-documents/action?action=verify-license-document', ['resources' => (string) $document->id])
            ->assertOk();

        $notification = $member->notifications()->sole();
        $this->assertSame(LicenseDocumentReviewed::class, $notification->type);
        $this->assertSame(['document_id' => $document->id, 'status' => 'approved'], $notification->data); // ids only
    }

    public function test_a_new_tax_document_notifies_the_member(): void
    {
        $member = $this->member();
        $document = TaxDocument::factory()->for($member)->create();

        $notification = $member->notifications()->sole();
        $this->assertSame(TaxDocumentAvailable::class, $notification->type);
        $this->assertSame(['document_id' => $document->id], $notification->data);
    }

    public function test_renewal_notices_go_out_once_per_period_within_30_days(): void
    {
        $soon = $this->member();
        $soon->memberProfile->forceFill(['paid_through_at' => today()->addDays(20)])->save();
        $later = $this->member();
        $later->memberProfile->forceFill(['paid_through_at' => today()->addDays(45)])->save();
        $agency = $this->agency(2, ['paid_through_at' => today()->addDays(10)]);

        $this->artisan('app:send-renewal-notices')->assertSuccessful();
        $this->artisan('app:send-renewal-notices')->assertSuccessful();

        $this->assertSame(1, $soon->notifications()->where('type', RenewalDue::class)->count());
        $this->assertSame(['paid_through' => today()->addDays(20)->toDateString()], $soon->notifications()->first()->data);
        $this->assertSame(0, $later->notifications()->count());
        $this->assertSame(1, User::find($agency->principal_user_id)->notifications()->where('type', RenewalDue::class)->count());

        // A new period gets its own notice.
        $soon->memberProfile->forceFill(['paid_through_at' => today()->addDays(25)])->save();
        $this->artisan('app:send-renewal-notices');
        $this->assertSame(2, $soon->notifications()->count());
    }

    // Upload

    private function pdf(string $name = 'license.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, DemoFile::pdf('License'));
    }

    public function test_members_upload_a_license_document_encrypted_and_audited(): void
    {
        $member = $this->member();

        $this->actingAs($member)
            ->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'non_resident'])
            ->assertRedirect(route('member.dashboard'))
            ->assertSessionHas('status');

        $document = LicenseDocument::query()->whereBelongsTo($member)->sole();
        $this->assertSame('non_resident', $document->document_type);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertStringEndsWith('.pdf.enc', $document->stored_filename);
        $this->assertStringStartsNotWith('%PDF', Storage::disk(config('portal.documents_disk'))->get($document->stored_filename));
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_uploaded', 'user_id' => $member->id, 'resource_id' => $document->id]);
    }

    public function test_the_progress_bar_upload_gets_json(): void
    {
        $this->actingAs($this->member())
            ->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'resident'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJson(['redirect' => route('member.dashboard')]);
    }

    public function test_uploads_check_type_size_and_format(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        $this->postJson(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'passport'])->assertJsonValidationErrors('document_type');
        $this->postJson(route('member.license-documents.store'), ['file' => UploadedFile::fake()->createWithContent('license.txt', 'plain text'), 'document_type' => 'resident'])->assertJsonValidationErrors('file');
        $this->postJson(route('member.license-documents.store'), ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'), 'document_type' => 'resident'])->assertJsonValidationErrors('file');

        $this->assertSame(0, LicenseDocument::query()->count());
    }

    public function test_agency_people_and_restricted_members_cannot_upload(): void
    {
        $agency = $this->agency(2);
        $person = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first()->user;

        $this->actingAs($person)->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'resident'])->assertForbidden();

        $restricted = $this->member();
        $restricted->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()->subMonth()])->save();
        $this->actingAs($restricted)->post(route('member.license-documents.store'), ['file' => $this->pdf(), 'document_type' => 'resident'])->assertForbidden();

        $this->assertSame(0, LicenseDocument::query()->count());
    }
}
