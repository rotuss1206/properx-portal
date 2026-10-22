<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\TaxDocument;
use App\Support\BlindIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Encrypted casts for designated fields and blind indexes for lookups (KEENEY platform rule).
 */
class EncryptedFieldsTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_member_identifiers_are_encrypted_at_rest(): void
    {
        $profile = $this->member()->memberProfile;
        $raw = DB::table('member_profiles')->where('id', $profile->id)->first();

        $this->assertSame(MemberProfile::formatMemberNumber($profile->user_id), $profile->member_number);

        foreach (['member_number', 'npn', 'certificate_code'] as $column) {
            $this->assertNotEmpty($profile->{$column});
            $this->assertNotSame($profile->{$column}, $raw->{$column}, "{$column} is stored in plain text");
            $this->assertStringNotContainsString((string) $profile->{$column}, $raw->{$column});
            $this->assertSame(64, strlen($raw->{$column.'_index'}));
        }
    }

    public function test_document_metadata_is_encrypted_at_rest(): void
    {
        $member = $this->member();
        $license = LicenseDocument::factory()->for($member)->create(['admin_notes' => 'Blurry scan']);
        $tax = TaxDocument::factory()->for($member)->create(['document_type' => 'W-9', 'tax_year' => 2025]);

        $rawLicense = DB::table('license_documents')->where('id', $license->id)->first();
        $rawTax = DB::table('tax_documents')->where('id', $tax->id)->first();

        foreach (['original_filename', 'mime_type', 'admin_notes'] as $column) {
            $this->assertNotSame($license->{$column}, $rawLicense->{$column});
        }
        $this->assertSame('Blurry scan', $license->fresh()->admin_notes);

        foreach (['document_type', 'tax_year', 'original_filename'] as $column) {
            $this->assertNotSame((string) $tax->{$column}, $rawTax->{$column});
        }
        $this->assertSame('W-9', $tax->fresh()->document_type);
        $this->assertSame('2025', (string) $tax->fresh()->tax_year);
    }

    public function test_blind_index_lookup_is_exact_and_ignores_case_and_spaces(): void
    {
        $profile = $this->member()->memberProfile;

        $this->assertTrue($profile->is(MemberProfile::query()->whereBlindIndex('npn', $profile->npn)->first()));
        $this->assertTrue($profile->is(MemberProfile::query()->whereBlindIndex('certificate_code', ' '.strtolower($profile->certificate_code))->first()));
        $this->assertNull(MemberProfile::query()->whereBlindIndex('npn', substr($profile->npn, 0, 4))->first());
    }

    public function test_blind_index_follows_value_changes(): void
    {
        $profile = $this->member()->memberProfile;
        $profile->update(['npn' => '99887766']);

        $this->assertTrue($profile->is(MemberProfile::query()->whereBlindIndex('npn', '99887766')->first()));
        $this->assertSame(BlindIndex::for('npn', '99887766'), DB::table('member_profiles')->where('id', $profile->id)->value('npn_index'));
    }

    public function test_seeded_records_are_indexed_although_model_events_are_muted(): void
    {
        // DatabaseSeeder runs with WithoutModelEvents.
        $profile = Model::withoutEvents(fn () => MemberProfile::factory()->create());

        $this->assertSame(MemberProfile::formatMemberNumber($profile->user_id), $profile->fresh()->member_number);
        $this->assertTrue($profile->is(MemberProfile::query()->whereBlindIndex('npn', $profile->npn)->first()));
        $this->assertTrue($profile->is(MemberProfile::query()->whereBlindIndex('certificate_code', $profile->certificate_code)->first()));
        $this->assertTrue($profile->is(MemberProfile::query()->whereBlindIndex('member_number', $profile->member_number)->first()));
    }

    public function test_clearing_a_value_clears_its_index(): void
    {
        $profile = $this->member()->memberProfile;
        $profile->update(['npn' => null]);

        $this->assertNull(DB::table('member_profiles')->where('id', $profile->id)->value('npn_index'));
    }

    public function test_indexes_are_separated_per_field(): void
    {
        $this->assertNotSame(BlindIndex::for('npn', '12345678'), BlindIndex::for('member_number', '12345678'));
    }

    public function test_blind_index_requires_its_own_key(): void
    {
        config(['portal.blind_index_key' => null]);

        $this->expectException(RuntimeException::class);

        BlindIndex::for('npn', '12345678');
    }

    public function test_nova_search_finds_members_by_npn_member_id_and_name(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile;
        $this->member(); // someone else

        $this->actingAs($this->staff(Role::MembershipCoordinator));

        $find = fn (string $search) => collect($this->getJson('/nova-api/member-profiles?search='.urlencode($search))
            ->assertOk()->json('resources'))->pluck('id.value')->all();

        $this->assertSame([$profile->id], $find($profile->npn));
        $this->assertSame([$profile->id], $find($profile->member_number));
        $this->assertSame([$profile->id], $find($profile->certificate_code));
        $this->assertContains($profile->id, $find(substr($profile->last_name, 0, 4)));
        $this->assertSame([], $find(substr($profile->npn, 0, 5)));
    }
}
