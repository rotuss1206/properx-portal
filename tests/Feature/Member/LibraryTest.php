<?php

namespace Tests\Feature\Member;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Member\MemberSearch;
use App\Models\AuditLog;
use App\Models\LibraryDocument;
use App\Models\User;
use App\Services\Documents\DocumentVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * /member/library: published documents only, categories, search that never reaches a URL, the
 * details page with its preview, and the same access as before.
 */
class LibraryTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    private function document(array $attributes = [], string $extension = 'pdf', string $contents = '%PDF-1.4 library'): LibraryDocument
    {
        return LibraryDocument::factory()->published()->create([
            'file_path' => app(DocumentVault::class)->put(LibraryDocument::DIRECTORY, $extension, $contents, encrypt: false),
            ...$attributes,
        ]);
    }

    private function canceled(): User
    {
        $member = $this->member();
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled, 'payment_status_updated_at' => now()->subMonth()])->save();

        return $member->fresh();
    }

    public function test_the_list_shows_published_documents_only_with_title_description_category_and_date(): void
    {
        $this->document(['title' => 'Annual ethics briefing', 'description' => 'What changed this year.', 'category' => 'Compliance Briefings', 'uploaded_at' => '2026-03-14 10:00:00']);
        LibraryDocument::factory()->create(['title' => 'Unfinished draft', 'published' => false]);

        $this->actingAs($this->member())->get(route('member.library'))->assertOk()
            ->assertSee('Annual ethics briefing')
            ->assertSee('What changed this year.')
            ->assertSee('Compliance Briefings')
            ->assertSee('14 Mar 2026')
            ->assertDontSee('Unfinished draft');
    }

    public function test_an_empty_library_says_so(): void
    {
        $this->actingAs($this->member())->get(route('member.library'))->assertOk()
            ->assertSee('No documents yet')
            ->assertDontSee('<table', false);
    }

    public function test_the_chips_filter_by_category(): void
    {
        $this->document(['title' => 'Ethics course', 'category' => 'Training']);
        $this->document(['title' => 'W-9 template', 'category' => 'Forms & Templates']);
        $this->actingAs($this->member());

        $this->get(route('member.library', ['category' => 'Training']))->assertOk()
            ->assertSee('Ethics course')
            ->assertDontSee('W-9 template');

        $this->get(route('member.library', ['category' => 'Forms & Templates']))->assertOk()
            ->assertSee('W-9 template')
            ->assertDontSee('Ethics course');

        $this->get(route('member.library', ['category' => 'Regulatory Updates']))->assertOk()
            ->assertSee('No matching documents');

        // An unknown value is no filter at all.
        $this->get(route('member.library', ['category' => 'nonsense']))->assertOk()
            ->assertSee('Ethics course')
            ->assertSee('W-9 template');
    }

    public function test_search_covers_title_description_and_category_and_every_word_must_match(): void
    {
        $this->document(['title' => 'Ethics course', 'description' => 'Annual requirement', 'category' => 'Training']);
        $this->document(['title' => 'W-9 template', 'description' => 'For contractors', 'category' => 'Forms & Templates']);
        $this->actingAs($this->member());

        $this->post(route('member.library.search'), ['q' => 'annual ETHICS'])->assertRedirect(route('member.library'));
        $this->get(route('member.library'))->assertOk()
            ->assertSee('Ethics course')
            ->assertDontSee('W-9 template')
            ->assertSee('1 result for');

        $this->post(route('member.library.search'), ['q' => 'contractors']);
        $this->get(route('member.library'))->assertSee('W-9 template')->assertDontSee('Ethics course');

        $this->post(route('member.library.search'), ['q' => 'zzzznothing']);
        $this->get(route('member.library'))->assertOk()
            ->assertSee('No matching documents')
            ->assertSee('ppx-library__clear', false);

        // Clearing brings everything back.
        $this->post(route('member.library.search'), ['q' => '']);
        $this->get(route('member.library'))->assertSee('Ethics course')->assertSee('W-9 template')->assertDontSee('ppx-library__clear', false);
    }

    public function test_the_search_words_never_reach_a_url_and_search_works_within_a_category(): void
    {
        $this->document(['title' => 'Ethics course', 'category' => 'Training']);
        $this->document(['title' => 'Ethics form', 'category' => 'Forms & Templates']);
        $this->actingAs($this->member());

        $response = $this->post(route('member.library.search'), ['q' => 'ethics', 'category' => 'Training']);

        $this->assertStringNotContainsString('ethics', strtolower((string) $response->headers->get('Location')));
        $response->assertRedirect(route('member.library', ['category' => 'Training']));

        $this->get(route('member.library', ['category' => 'Training']))->assertSee('Ethics course')->assertDontSee('Ethics form');
    }

    public function test_like_wildcards_are_plain_characters(): void
    {
        $this->document(['title' => 'Ethics course']);
        $this->actingAs($this->member());

        $this->post(route('member.library.search'), ['q' => '%%']);
        $this->get(route('member.library'))->assertOk()->assertSee('No matching documents');
    }

    public function test_the_list_is_paged_and_keeps_its_category(): void
    {
        LibraryDocument::factory()->published()->count(21)->create(['category' => 'Training']);

        $this->actingAs($this->member())->get(route('member.library', ['category' => 'Training']))->assertOk()
            ->assertSee('Page 1 of 2')
            ->assertSee('category=Training&amp;page=2', false);
    }

    public function test_the_details_page_has_description_category_date_and_download(): void
    {
        $document = $this->document(['title' => 'Ethics course', 'description' => "First line.\nSecond line.", 'category' => 'Training', 'uploaded_at' => '2026-03-14 10:00:00']);

        $this->actingAs($this->member())->get(route('member.library.show', $document))->assertOk()
            ->assertSee('Ethics course')
            ->assertSee('Second line.')
            ->assertSee('<dt>Category</dt><dd>Training</dd>', false)
            ->assertSee('<dt>Published</dt><dd>14 Mar 2026</dd>', false)
            ->assertSee(route('member.documents.download', ['type' => 'library', 'document' => $document->id]), false)
            ->assertSee(route('member.library'), false);
    }

    public function test_download_goes_through_the_signed_route_and_is_audited(): void
    {
        $member = $this->member();
        $document = $this->document();
        $this->actingAs($member);

        $signed = $this->get(route('member.documents.download', ['type' => 'library', 'document' => $document->id]))->assertRedirect()->headers->get('Location');

        $this->assertSame('%PDF-1.4 library', $this->get($signed)->assertOk()->streamedContent());
        $this->assertSame(1, AuditLog::where('action', 'document_downloaded')->where('user_id', $member->id)->where('resource_id', $document->id)->count());
    }

    public function test_an_image_is_previewed_in_the_panel_and_a_pdf_in_a_new_tab(): void
    {
        $member = $this->member();
        $image = $this->document(extension: 'png', contents: 'png-bytes');
        $pdf = $this->document();
        $this->actingAs($member);

        $this->get(route('member.library.show', $image))->assertOk()
            ->assertSee('<img class="ppx-docs__preview-image" src="'.route('member.library.preview', $image).'"', false);

        $this->get(route('member.library.show', $pdf))->assertOk()
            ->assertSee('href="'.route('member.library.preview', $pdf).'" target="_blank" rel="noopener"', false)
            ->assertDontSee('<iframe', false);

        $response = $this->get(route('member.library.preview', $image))->assertOk();
        $this->assertSame('png-bytes', $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame(1, AuditLog::where('action', 'document_previewed')->where('resource_id', $image->id)->count());
    }

    public function test_drafts_are_not_there(): void
    {
        $draft = LibraryDocument::factory()->create(['published' => false]);
        $this->actingAs($this->member());

        $this->get(route('member.library.show', $draft))->assertNotFound();
        $this->get(route('member.library.preview', $draft))->assertNotFound();
        $this->get(route('member.documents.download', ['type' => 'library', 'document' => $draft->id]))->assertForbidden();
    }

    public function test_a_canceled_membership_closes_it_and_agency_people_keep_it(): void
    {
        $document = $this->document(['title' => 'Ethics course']);

        $this->actingAs($this->canceled());
        $this->get(route('member.library'))->assertNotFound();
        $this->get(route('member.library.show', $document))->assertNotFound();
        $this->get(route('member.library.preview', $document))->assertNotFound();
        $this->post(route('member.library.search'), ['q' => 'x'])->assertNotFound();
        $this->get(route('member.documents.download', ['type' => 'library', 'document' => $document->id]))->assertForbidden();
        $this->get('/nova/resources/library-documents')->assertRedirect(route('member.dashboard'));

        $agency = $this->agency(2);
        foreach ([$agency->principal()->first(), $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first()->user] as $person) {
            $this->actingAs($person)->get(route('member.library'))->assertOk()->assertSee('Ethics course');
            $this->get(route('member.library.show', $document))->assertOk();
        }
    }

    public function test_guests_staff_and_a_closed_member_area_do_not_reach_it(): void
    {
        $this->get(route('member.library'))->assertRedirect();

        $this->actingAs($this->staff(Role::ComplianceSpecialist))->get(route('member.library'))->assertRedirect('/nova');

        config(['portal.member_area.enabled' => false]);
        $this->actingAs($this->member())->get('/member/library')->assertNotFound();
    }

    public function test_the_header_search_opens_the_details_page(): void
    {
        $member = $this->member();
        $document = $this->document(['title' => 'Ethics course']);

        $result = MemberSearch::for($member, 'ethics')->firstWhere('kind', 'library');

        $this->assertSame(route('member.library.show', $document), $result['url']);
    }

    public function test_the_pages_run_under_the_strict_csp(): void
    {
        $document = $this->document();
        $this->actingAs($this->member());

        foreach ([route('member.library'), route('member.library.show', $document)] as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bnonce=)[^>]*>/', $content);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $content);
            $this->assertStringNotContainsString('wire:click', $content);
        }
    }
}
