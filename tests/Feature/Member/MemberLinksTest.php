<?php

namespace Tests\Feature\Member;

use App\Enums\PaymentStatus;
use App\Member\MemberMenu;
use App\Models\TaxDocument;
use App\Models\User;
use App\Notifications\Member\RenewalDue;
use App\Services\Documents\DocumentVault;
use Database\Seeders\Support\DemoFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Nothing in the member area leads to an error: every link on every page reachable from the
 * dashboard opens, for each kind of member. Pages not built yet say so and lead back.
 */
class MemberLinksTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    /**
     * Follows every same-site link from the dashboard; returns the URLs it opened.
     *
     * @return list<string>
     */
    private function assertEveryLinkOpens(User $user): array
    {
        $this->actingAs($user);

        $queue = [route('member.dashboard')];
        $seen = [];

        while ($queue !== []) {
            $url = array_shift($queue);

            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $response = $this->get($url);
            $this->assertLessThan(400, $response->getStatusCode(), 'Leads to an error: '.$this->pathOf($url));

            if ($response->isRedirect()) {
                $queue[] = $response->headers->get('Location');

                continue;
            }

            preg_match_all('/<a\b[^>]*\bhref="([^"#][^"]*)"/i', (string) $response->getContent(), $links);

            foreach ($links[1] as $href) {
                $link = $this->absolute(html_entity_decode($href), $url);

                if (str_starts_with($link, config('app.url'))) {
                    $queue[] = $link;
                }
            }
        }

        return array_keys($seen);
    }

    private function absolute(string $href, string $base): string
    {
        return str_starts_with($href, '?') ? strtok($base, '?').$href : $href;
    }

    private function pathOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    /**
     * @param  list<string>  $opened
     * @return list<string>
     */
    private function paths(array $opened): array
    {
        return array_values(array_unique(array_map(fn (string $url) => $this->pathOf($url), $opened)));
    }

    private function withDocumentsAndNotifications(User $user): User
    {
        $this->actingAs($user)->post(route('member.license-documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('license.pdf', DemoFile::pdf('License')),
            'document_type' => 'resident',
        ]);

        TaxDocument::factory()->for($user)->create([
            'stored_filename' => app(DocumentVault::class)->put('tax-documents', 'pdf', DemoFile::pdf('Tax'), true),
        ]);

        $user->notify(new RenewalDue(today()->addDays(20)->toDateString()));

        return $user;
    }

    public function test_every_link_opens_for_an_individual_member(): void
    {
        $paths = $this->paths($this->assertEveryLinkOpens($this->withDocumentsAndNotifications($this->member())));

        foreach (array_keys(MemberMenu::PAGES) as $page) {
            $this->assertContains($this->pathOf(route('member.'.$page)), $paths);
        }

        $this->assertContains('/nova/user-security', $paths);
        $this->assertNotEmpty(preg_grep('#^/documents/.+/download$#', $paths));
        $this->assertNotEmpty(preg_grep('#^/certificates/\d+/download$#', $paths));
    }

    public function test_every_link_opens_for_an_agency_principal(): void
    {
        $principal = $this->agency(2)->principal()->first();

        $paths = $this->paths($this->assertEveryLinkOpens($principal));

        $this->assertContains($this->pathOf(route('member.billing')), $paths);
        $this->assertNotContains($this->pathOf(route('member.license-documents')), $paths);
    }

    public function test_every_link_opens_for_other_agency_people(): void
    {
        $agency = $this->agency(2);
        $person = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first()->user;

        $paths = $this->paths($this->assertEveryLinkOpens($person));

        $this->assertNotContains($this->pathOf(route('member.billing')), $paths);
        $this->assertNotContains($this->pathOf(route('member.license-documents')), $paths);
    }

    public function test_every_link_opens_for_a_canceled_membership(): void
    {
        $member = $this->withDocumentsAndNotifications($this->member());
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled])->save();

        $this->assertEveryLinkOpens($member->fresh());
    }

    public function test_pages_not_built_yet_say_so_and_lead_back_to_the_dashboard(): void
    {
        $this->actingAs($this->member());

        foreach (array_keys(MemberMenu::PAGES) as $page) {
            if ($page === 'dashboard') {
                continue;
            }

            $response = $this->get(route('member.'.$page))->assertOk()
                ->assertSee('This section is coming soon')
                ->assertSee('Back to dashboard')
                ->assertSee('ppx-btn--primary', false);

            $this->assertMatchesRegularExpression('#<a\b[^>]*href="'.preg_quote(route('member.dashboard'), '#').'"[^>]*ppx-empty__action#s', $response->getContent());
        }
    }
}
