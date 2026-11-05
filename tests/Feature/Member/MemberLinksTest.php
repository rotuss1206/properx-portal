<?php

namespace Tests\Feature\Member;

use App\Enums\PaymentStatus;
use App\Enums\ReminderType;
use App\Enums\VerificationStatus;
use App\Member\MemberMenu;
use App\Models\LicenseDocument;
use App\Models\TaxDocument;
use App\Models\User;
use App\Notifications\LicenseExpiryReminder;
use App\Notifications\Member\RenewalDue;
use App\Services\Documents\DocumentVault;
use Database\Seeders\Support\DemoFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Nova\Http\Middleware\HandleInertiaRequests;
use Laravel\Nova\Nova;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Nothing in the member area leads to an error: every link on every page reachable from the
 * dashboard opens, for each kind of member. The library stays in Nova for now, tax documents lead to the dashboard's Documents tab, notification preferences to the profile
 * page; the only page left says it is coming soon and leads back.
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
        return match (true) {
            str_starts_with($href, '?') => strtok($base, '?').$href,
            str_starts_with($href, '/') => rtrim(config('app.url'), '/').$href,
            default => $href,
        };
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

    /**
     * @return array<string, string>
     */
    private function menu(User $user): array
    {
        return collect(MemberMenu::items($user, null))->pluck('url', 'key')->all();
    }

    public function test_every_link_opens_for_an_individual_member(): void
    {
        $member = $this->withDocumentsAndNotifications($this->member());
        $profile = $member->memberProfile->getKey();

        $paths = $this->paths($this->assertEveryLinkOpens($member));

        foreach ($this->menu($member) as $url) {
            $this->assertContains($this->pathOf($url), $paths);
        }

        $this->assertContains('/member/profile', $paths);
        $this->assertContains('/member/certificate', $paths);
        $this->assertNotContains("/nova/resources/member-profiles/{$profile}", $paths);
        $this->assertNotContains("/nova/resources/member-profiles/{$profile}/edit", $paths);
        $this->assertContains('/member/license-documents', $paths);
        $this->assertContains('/member/license-documents/upload', $paths);
        $this->assertNotContains('/nova/resources/license-documents', $paths);
        $this->assertContains('/nova/resources/library-documents', $paths);
        $this->assertNotEmpty(preg_grep('#^/documents/.+/download$#', $paths));
        $this->assertNotEmpty(preg_grep('#^/certificates/\d+/download$#', $paths));
    }

    public function test_every_link_opens_for_an_agency_principal(): void
    {
        $principal = $this->agency(2)->principal()->first();

        $paths = $this->paths($this->assertEveryLinkOpens($principal));

        $this->assertContains($this->pathOf(route('member.billing')), $paths);
        $this->assertContains('/nova/resources/library-documents', $paths);
        $this->assertNotContains('/nova/resources/license-documents', $paths);
        $this->assertNotContains('/member/license-documents', $paths);
    }

    public function test_every_link_opens_for_other_agency_people(): void
    {
        $agency = $this->agency(2);
        $person = $agency->members()->where('user_id', '!=', $agency->principal_user_id)->first()->user;

        $paths = $this->paths($this->assertEveryLinkOpens($person));

        $this->assertNotContains($this->pathOf(route('member.billing')), $paths);
        $this->assertNotContains('/nova/resources/license-documents', $paths);
        $this->assertNotContains('/member/license-documents', $paths);
    }

    public function test_every_link_opens_for_a_canceled_membership_with_closed_pages_left_out(): void
    {
        $member = $this->withDocumentsAndNotifications($this->member());
        $member->memberProfile->forceFill(['payment_status' => PaymentStatus::Canceled])->save();
        $member = $member->fresh();

        $paths = $this->paths($this->assertEveryLinkOpens($member));

        $this->assertArrayNotHasKey('library', $this->menu($member));
        $this->assertArrayNotHasKey('license-documents', $this->menu($member));
        $this->assertNotContains('/nova/resources/library-documents', $paths);
        $this->assertNotContains('/member/license-documents', $paths);
        $this->assertContains('/member/certificate', $paths);
        $this->assertEmpty(preg_grep('#^/certificates/#', $paths));

        // Opened by hand, Nova's closed pages lead back instead of showing an error.
        $this->get('/nova/resources/library-documents')->assertRedirect(route('member.dashboard'));
        $this->get('/nova/resources/license-documents')->assertRedirect(route('member.dashboard'));
    }

    public function test_only_billing_says_coming_soon_and_leads_back_to_the_dashboard(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        $placeholders = collect($this->menu($member))
            ->filter(fn (string $url) => str_contains((string) $this->get($url)->getContent(), 'This section is coming soon'))
            ->keys()
            ->all();

        $this->assertSame(['billing'], $placeholders);

        $response = $this->get(route('member.billing'))->assertOk()->assertSee('Back to dashboard');

        $this->assertMatchesRegularExpression('#<a\b[^>]*href="'.preg_quote(route('member.dashboard'), '#').'"[^>]*ppx-empty__action#s', $response->getContent());
    }

    public function test_tax_documents_lead_to_the_dashboards_documents_tab_filtered_to_tax(): void
    {
        $member = $this->withDocumentsAndNotifications($this->member());
        $url = route('member.dashboard', ['tab' => 'documents', 'filter' => 'tax']);

        $this->assertSame($url, $this->menu($member)['tax-documents']);

        $this->actingAs($member)->get(route('member.tax-documents'))->assertRedirect($url);
        $this->get($url)->assertOk()
            ->assertSee('All documents')
            ->assertSee('Tax year')
            ->assertDontSee('Uploaded ');
    }

    public function test_profile_and_notifications_lead_to_the_profile_page_from_every_old_address(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile->getKey();
        $menu = $this->menu($member);
        $page = route('member.profile');

        $this->assertSame($page, $menu['profile']);
        $this->assertSame($page.'#notifications', $menu['notifications']);

        $this->actingAs($member)->get($page)->assertOk();
        $this->get(route('member.notifications'))->assertRedirect($page.'#notifications');

        // Old links into Nova, also from emails already sent.
        $this->get("/nova/resources/member-profiles/{$profile}")->assertRedirect($page);
        $this->get("/nova/resources/member-profiles/{$profile}/edit")->assertRedirect($page);
        $this->get('/nova/resources/member-profiles')->assertRedirect($page);
        $this->get("/nova/resources/member-profiles/{$profile}/edit?section=notifications")->assertRedirect($page.'#notifications');
        $this->get("/nova/resources/member-profiles/{$profile}/edit?section=certificate")->assertRedirect(route('member.certificate'));

        // The sidebar's links in Nova are Inertia visits.
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->get("/nova/resources/member-profiles/{$profile}/edit?section=notifications", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $page.'#notifications');
    }

    public function test_the_header_leads_to_the_profile_page_and_its_security_section(): void
    {
        $this->actingAs($this->member())->get(route('member.dashboard'))->assertOk()
            ->assertSee('href="'.route('member.profile').'#security"', false)
            ->assertDontSee('href="'.route('nova.pages.user-security').'"', false);
    }

    public function test_license_documents_lead_to_the_member_area_from_every_old_address(): void
    {
        $member = $this->member();
        $page = route('member.license-documents');
        $document = LicenseDocument::factory()->for($member)->create();

        $this->assertSame($page, $this->menu($member)['license-documents']);

        $this->actingAs($member)->get($page)->assertOk();

        // Old links into Nova, also from emails already sent.
        $this->get('/nova/resources/license-documents')->assertRedirect($page);
        $this->get("/nova/resources/license-documents/{$document->id}")->assertRedirect($page);

        // The sidebar's links in Nova are Inertia visits.
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->get('/nova/resources/license-documents', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $page);

        // The dashboard's Documents tab links to it too.
        $this->get(route('member.dashboard', ['tab' => 'documents']))->assertOk()->assertSee('href="'.$page.'"', false);
    }

    public function test_the_certificate_leads_to_the_member_area_from_every_old_address(): void
    {
        $member = $this->member();
        $profile = $member->memberProfile->getKey();
        $page = route('member.certificate');

        $this->assertSame($page, $this->menu($member)['certificate']);

        $this->actingAs($member)->get($page)->assertOk();

        $this->get("/nova/resources/member-profiles/{$profile}?section=certificate")->assertRedirect($page);

        // The sidebar's links in Nova are Inertia visits.
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->get("/nova/resources/member-profiles/{$profile}?section=certificate", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $page);

        // The dashboard's card leads to it.
        $this->get(route('member.dashboard'))->assertOk()->assertSee('href="'.$page.'"', false);
    }

    public function test_the_library_stays_in_nova(): void
    {
        $member = $this->member();
        $menu = $this->menu($member);

        $expected = [
            'library' => '/nova/resources/library-documents',
        ];

        $this->actingAs($member);

        foreach ($expected as $page => $path) {
            $this->assertSame($path, $menu[$page]);

            // Old links to the member area's address keep working, and the Nova page opens.
            $this->get(route('member.'.$page))->assertRedirect($path);
            $this->get($path)->assertOk();
        }
    }

    public function test_members_get_back_to_the_member_area_from_nova(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        // The sidebar's Dashboard item and its logo.
        $this->get(Nova::url('/dashboards/my-membership'))->assertRedirect(route('member.dashboard'));
        $this->get(Nova::url('/'))->assertRedirect();
        $this->get(Nova::initialPathUrl(request()))->assertRedirect(route('member.dashboard'));
    }

    public function test_agency_people_are_not_kept_on_nova_license_documents(): void
    {
        $principal = $this->agency(2)->principal()->first();

        $this->actingAs($principal)->get('/nova/resources/license-documents')->assertRedirect(route('member.dashboard'));
        $this->get('/member/license-documents')->assertNotFound();
    }

    public function test_the_reminders_unsubscribe_link_opens_the_notification_preferences(): void
    {
        $member = $this->member('expiring');
        $url = LicenseExpiryReminder::preferencesUrl($member->memberProfile);

        $message = (new LicenseExpiryReminder($member->memberProfile, ReminderType::Days30))->toMail($member);
        $this->assertStringContainsString($url, implode(' ', $message->outroLines));

        $this->actingAs($member)->followingRedirects()->get($url)->assertOk()
            ->assertSee('id="notifications"', false)
            ->assertSee('role="switch"', false);
    }

    public function test_a_signed_out_member_reaches_the_notification_preferences_after_signing_in(): void
    {
        $member = $this->member('expiring');
        $url = LicenseExpiryReminder::preferencesUrl($member->memberProfile);

        $this->get($url)->assertRedirect();
        $this->assertGuest();
        $this->assertSame($url, session('url.intended'));
    }

    public function test_novas_api_still_only_withdraws_pending_license_documents(): void
    {
        $member = $this->member();
        $pending = LicenseDocument::factory()->for($member)->create(['verification_status' => VerificationStatus::Pending]);
        $approved = LicenseDocument::factory()->for($member)->create(['verification_status' => VerificationStatus::Approved]);

        $this->actingAs($member)->deleteJson('/nova-api/license-documents?resources[]='.$pending->id.'&resources[]='.$approved->id)->assertOk();

        $this->assertModelMissing($pending);
        $this->assertModelExists($approved);
    }
}
