<?php

namespace Tests\Feature\Nova;

use App\Enums\NotificationCategory;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\NotificationSetting;
use App\Models\TaxDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * A member sees and edits only their own data.
 */
class MemberSelfServiceTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_member_lists_only_own_records(): void
    {
        $member = $this->member();
        $other = $this->member();

        LicenseDocument::factory()->count(2)->for($member)->create();
        LicenseDocument::factory()->count(3)->for($other)->create();
        TaxDocument::factory()->for($member)->create();
        TaxDocument::factory()->count(2)->for($other)->create();
        LibraryDocument::factory()->count(2)->published()->create();
        LibraryDocument::factory()->draft()->create();

        $this->actingAs($member);

        $this->assertSame([$member->memberProfile->id], $this->indexIds('member-profiles'));
        $this->assertEqualsCanonicalizing($member->licenseDocuments()->pluck('id')->all(), $this->indexIds('license-documents'));
        $this->assertEqualsCanonicalizing($member->taxDocuments()->pluck('id')->all(), $this->indexIds('tax-documents'));
        $this->assertEqualsCanonicalizing(LibraryDocument::published()->pluck('id')->all(), $this->indexIds('library-documents'));
    }

    public function test_member_cannot_open_other_members_records(): void
    {
        $member = $this->member();
        $other = $this->member();
        $otherDocument = LicenseDocument::factory()->for($other)->create();
        $otherTax = TaxDocument::factory()->for($other)->create();
        $draft = LibraryDocument::factory()->draft()->create();

        $this->actingAs($member);

        $this->getJson("/nova-api/member-profiles/{$other->memberProfile->id}")->assertForbidden();
        $this->getJson("/nova-api/license-documents/{$otherDocument->id}")->assertForbidden();
        $this->getJson("/nova-api/tax-documents/{$otherTax->id}")->assertForbidden();
        $this->getJson("/nova-api/library-documents/{$draft->id}")->assertForbidden();
        $this->getJson("/nova-api/member-profiles/{$member->memberProfile->id}")->assertOk();
    }

    public function test_member_updates_own_profile_and_notification_settings(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;

        $this->actingAs($member)
            ->putJson("/nova-api/member-profiles/{$profile->id}", [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'phone' => '(555) 123-4567',
                'license_state' => 'TX',
                'license_type' => $profile->license_type,
                'license_expires_at' => now()->addDays(10)->toDateString(),
                'notify_license_expiry' => '0',
                'notify_compliance_briefings' => '1',
                'notify_tax_documents' => '1',
                'notify_announcements' => '0',
            ])
            ->assertOk();

        $profile->refresh();
        $this->assertSame('Jane', $profile->first_name);
        $this->assertSame('expiring', $profile->license_status->value);

        $settings = NotificationSetting::where('user_id', $member->id)->pluck('enabled', 'category');
        $this->assertFalse($settings[NotificationCategory::LicenseExpiry->value]);
        $this->assertTrue($settings[NotificationCategory::ComplianceBriefings->value]);
        $this->assertFalse($settings[NotificationCategory::Announcements->value]);
    }

    public function test_member_cannot_update_another_profile(): void
    {
        $member = $this->member();
        $other = $this->member();

        $this->actingAs($member)
            ->putJson("/nova-api/member-profiles/{$other->memberProfile->id}", [
                'first_name' => 'Hacked',
                'last_name' => 'Hacked',
            ])
            ->assertForbidden();

        $this->assertNotSame('Hacked', $other->memberProfile->fresh()->first_name);
    }

    public function test_member_cannot_create_profiles_or_library_documents(): void
    {
        $this->actingAs($this->member());

        $this->getJson('/nova-api/member-profiles/creation-fields')->assertForbidden();
        $this->getJson('/nova-api/library-documents/creation-fields')->assertForbidden();
        $this->getJson('/nova-api/tax-documents/creation-fields')->assertForbidden();
        $this->getJson('/nova-api/license-documents/creation-fields')->assertOk();
    }

    public function test_member_downloads_only_own_certificate(): void
    {
        $member = $this->member();
        $other = $this->member();

        $this->actingAs($member)
            ->get(route('certificates.show', $member->memberProfile))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('certificates.show', $other->memberProfile))->assertForbidden();
    }

    /**
     * @return list<int>
     */
    private function indexIds(string $resource): array
    {
        return collect($this->getJson("/nova-api/{$resource}?perPage=100")->assertOk()->json('resources'))
            ->map(fn (array $row) => $row['id']['value'])
            ->all();
    }
}
