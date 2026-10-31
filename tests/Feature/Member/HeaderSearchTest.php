<?php

namespace Tests\Feature\Member;

use App\Enums\PaymentStatus;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Header search of the member area (App\Member\MemberSearch, SearchController,
 * public/portal/search.js): own documents and the published library, the query never in a URL.
 */
class HeaderSearchTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    public function test_the_header_shows_an_accessible_search_field(): void
    {
        $this->actingAs($this->member())->get('/member')->assertOk()
            ->assertSee('role="search"', false)
            ->assertSee('placeholder="Search"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('aria-controls="ppx-search-list"', false)
            ->assertSee('role="listbox"', false)
            ->assertSee('method="POST" action="'.route('member.search.submit').'"', false)
            ->assertSee('portal/search.js?v=', false);
    }

    public function test_suggestions_cover_own_documents_and_the_published_library_only(): void
    {
        $member = $this->member();
        $other = $this->member();
        TaxDocument::factory()->for($member)->create(['document_type' => '1099-NEC', 'tax_year' => 2025]);
        TaxDocument::factory()->for($other)->create(['document_type' => '1099-MISC', 'tax_year' => 2025]);
        LibraryDocument::factory()->published()->create(['title' => 'Form 1099 filing guide', 'category' => 'Forms & Templates']);
        LibraryDocument::factory()->create(['title' => '1099 draft notes', 'published' => false]);

        $response = $this->actingAs($member)->postJson(route('member.search.suggest'), ['q' => '1099'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('total', 2);

        $names = collect($response->json('results'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['1099-NEC', 'Form 1099 filing guide'], $names);
        $this->assertEqualsCanonicalizing(['Tax', 'Library'], collect($response->json('results'))->pluck('type')->all());

        // Every word must match; type and year words count.
        $this->postJson(route('member.search.suggest'), ['q' => 'tax 2025'])->assertJsonPath('total', 1);
        $this->postJson(route('member.search.suggest'), ['q' => 'tax 2019'])->assertJsonPath('total', 0);
        $this->postJson(route('member.search.suggest'), ['q' => 'x'])->assertJsonPath('total', 0);
    }

    public function test_license_documents_match_by_type_never_by_file_name(): void
    {
        $member = $this->member();
        LicenseDocument::factory()->for($member)->create(['document_type' => 'resident', 'original_filename' => 'jordan-secret-scan.pdf']);

        $this->actingAs($member)->postJson(route('member.search.suggest'), ['q' => 'resident'])
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.name', 'Resident license')
            ->assertJsonPath('results.0.url', route('member.documents.download', ['type' => 'license', 'document' => LicenseDocument::first()->id]));

        $this->postJson(route('member.search.suggest'), ['q' => 'secret'])->assertJsonPath('total', 0);
    }

    public function test_restricted_members_find_no_library_or_license_documents(): void
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()->subMonths(2)])->save();
        LibraryDocument::factory()->published()->create(['title' => 'Annual compliance briefing']);
        LicenseDocument::factory()->for($member)->create(['document_type' => 'resident']);

        $this->actingAs($member)->postJson(route('member.search.suggest'), ['q' => 'compliance'])->assertJsonPath('total', 0);
        $this->postJson(route('member.search.suggest'), ['q' => 'resident'])->assertJsonPath('total', 0);
    }

    public function test_enter_opens_the_results_page_without_the_query_in_the_url_or_the_log(): void
    {
        Log::spy();
        $member = $this->member();
        TaxDocument::factory()->for($member)->create(['document_type' => 'W-9', 'tax_year' => 2026]);

        $this->actingAs($member)->post(route('member.search.submit'), ['q' => 'W-9'])
            ->assertRedirect(route('member.search'));

        $this->assertStringNotContainsString('W-9', route('member.search'));

        $this->get(route('member.search'))->assertOk()
            ->assertSee('1 result for “W-9”', false)
            ->assertSee('Tax year 2026')
            ->assertSee('class="ppx-member"', false);

        $this->post(route('member.search.submit'), ['q' => 'nothing-like-this'])->assertRedirect(route('member.search'));
        $this->get(route('member.search'))->assertSee('No matching documents');

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_library_results_open_through_the_member_area(): void
    {
        $member = $this->member();
        $document = LibraryDocument::factory()->published()->create();
        $draft = LibraryDocument::factory()->create(['published' => false]);

        $this->actingAs($member)->get(route('member.documents.download', ['type' => 'library', 'document' => $document->id]))
            ->assertRedirect();
        $this->get(route('member.documents.download', ['type' => 'library', 'document' => $draft->id]))->assertForbidden();
    }
}
