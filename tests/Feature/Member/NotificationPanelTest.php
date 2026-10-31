<?php

namespace Tests\Feature\Member;

use App\Enums\Role;
use App\Notifications\Member\RenewalDue;
use App\Notifications\Member\TaxDocumentAvailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Nova\Notifications\NovaNotification;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * The notifications panel (components/member/notify-panel.blade.php, public/portal/notify.js;
 * Nova: resources/js/nova/header.js): on every member page, open by default on the dashboard,
 * nothing marked read by showing it, "Mark all read", opening one marks it read.
 */
class NotificationPanelTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config(['portal.member_area.enabled' => true]);
    }

    public function test_every_member_page_has_the_panel_open_by_default_only_on_the_dashboard(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get('/member')->assertOk()
            ->assertSee('id="ppx-notify"', false)
            ->assertSee('data-ppx-notify-toggle aria-controls="ppx-notify" aria-expanded="false"', false)
            ->assertSee('data-context="member-dashboard" data-default="open"', false)
            ->assertSee('portal/notify.css?v=', false)
            ->assertSee('You are all caught up');

        $this->get('/member/profile')->assertOk()
            ->assertSee('id="ppx-notify"', false)
            ->assertSee('data-context="member" data-default="closed"', false);
    }

    public function test_showing_notifications_does_not_mark_them_read(): void
    {
        $member = $this->member();
        $member->notify(new RenewalDue(today()->addDays(12)->toDateString()));
        $member->notify(new TaxDocumentAvailable(1));

        $this->actingAs($member)->get('/member')->assertOk()
            ->assertSee('<span class="ppx-header__count" aria-hidden="true">2</span>', false)
            ->assertSee('Renewal reminder — 12 days')
            ->assertSee('New tax document available')
            ->assertSee('ppx-notify__item is-unread', false)
            ->assertSee('2 unread notifications')
            ->assertSee('Mark all read');

        $this->get('/member/profile')->assertOk()->assertSee('ppx-notify__item is-unread', false);

        $this->assertSame(2, $member->unreadNotifications()->count());
    }

    public function test_opening_a_notification_marks_it_read_and_leads_to_its_page(): void
    {
        $member = $this->member();
        $member->notify(new TaxDocumentAvailable(1));
        $member->notify(new RenewalDue(today()->addDays(12)->toDateString()));
        $tax = $member->notifications()->where('type', TaxDocumentAvailable::class)->first();

        $this->actingAs($member)->get(route('member.notifications.open', $tax->id))
            ->assertRedirect(route('member.dashboard', ['tab' => 'documents', 'filter' => 'tax']));

        $this->assertNotNull($tax->fresh()->read_at);
        $this->assertSame(1, $member->unreadNotifications()->count());
    }

    public function test_members_open_only_their_own_notifications(): void
    {
        $owner = $this->member();
        $owner->notify(new TaxDocumentAvailable(1));
        $notification = $owner->notifications()->first();

        $this->actingAs($this->member())->get(route('member.notifications.open', $notification->id))->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_clears_the_members_own_notifications(): void
    {
        $member = $this->member();
        $other = $this->member();
        $member->notify(new TaxDocumentAvailable(1));
        $member->notify(new TaxDocumentAvailable(2));
        $other->notify(new TaxDocumentAvailable(3));

        $this->actingAs($member)->postJson(route('member.notifications.read-all'))->assertOk()->assertExactJson(['unread' => 0]);

        $this->assertSame(0, $member->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());

        // Without JavaScript: a plain form post comes back to the page.
        $member->notify(new TaxDocumentAvailable(4));
        $this->from('/member/profile')->post(route('member.notifications.read-all'))->assertRedirect('/member/profile');
        $this->assertSame(0, $member->unreadNotifications()->count());

        $this->get('/member')->assertDontSee('ppx-header__count', false)->assertDontSee('ppx-notify__item is-unread', false);
    }

    public function test_the_upload_sits_in_the_panel_on_the_dashboard_for_individual_members_only(): void
    {
        $member = $this->member();

        $html = $this->actingAs($member)->get('/member')->assertOk()->getContent();
        $this->assertGreaterThan(strpos($html, 'id="ppx-notify"'), strpos($html, 'data-ppx-upload'));

        $this->get('/member/profile')->assertOk()->assertDontSee('data-ppx-upload', false);

        $agency = $this->agency(2);
        $this->actingAs($agency->principal()->first())->get('/member')->assertOk()
            ->assertSee('id="ppx-notify"', false)
            ->assertDontSee('data-ppx-upload', false);
    }

    public function test_nova_draws_the_same_panel_from_its_own_notifications(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $director->notify(NovaNotification::make()->message('Weekly export ready'));

        $this->actingAs($director)->get('/nova/dashboards/main')->assertOk()
            ->assertSee('portal/notify.css?v=', false)
            ->assertSee('portal/notify.js?v=', false)
            ->assertSee('data-context="nova" data-default="closed"', false);

        $this->getJson('/nova-api/nova-notifications')->assertOk()->assertJsonPath('unread', 1);

        $js = file_get_contents(resource_path('js/nova/header.js'));

        foreach (['NotificationCenter', 'nova/fetchNotifications', 'nova/markNotificationAsRead', 'nova/markAllNotificationsAsRead', 'data-ppx-notify-toggle', 'ppx-notify__item'] as $part) {
            $this->assertStringContainsString($part, $js);
        }

        // Nova's own panel stays when the replacement fails; the push layout follows only ours.
        $this->assertStringContainsString('ppx-nova-notify', $js);
        $this->assertStringContainsString('.ppx-nova-notify[data-ppx-notify="open"]', file_get_contents(resource_path('css/nova/header.css')));
    }
}
