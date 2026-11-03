<?php

namespace Tests\Feature;

use App\Enums\ReminderType;
use App\Enums\Role;
use App\Notifications\LicenseExpiryReminder;
use App\Notifications\MemberInvited;
use App\Notifications\ScheduledTaskFailed;
use App\Notifications\StaffAccountCreated;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Every email carries the ProPerX Alliance brand, never the framework's (a server without
 * APP_NAME used to send "Laravel" logos and signatures), and no member data in the body.
 */
class MailBrandingTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        // What a server without APP_NAME looked like before; the brand must not depend on it.
        config(['app.name' => 'Laravel']);
    }

    /**
     * @return array{html: string, text: string}
     */
    private function render(MailMessage $mail): array
    {
        $markdown = app(Markdown::class);

        return [
            'html' => (string) $markdown->render($mail->markdown, $mail->data()),
            'text' => (string) $markdown->renderText($mail->markdown, $mail->data()),
        ];
    }

    /**
     * @param  array{html: string, text: string}  $rendered
     */
    private function assertBranded(array $rendered): void
    {
        foreach ($rendered as $format => $content) {
            $this->assertStringNotContainsStringIgnoringCase('laravel', $content, "{$format} mentions the framework");
            $this->assertStringContainsString('ProPerX Alliance', $content, "{$format} lacks the brand");
            $this->assertStringContainsString('© '.date('Y').' ProPerX Alliance', $content, "{$format} footer");
        }

        // Text logo only: no remote images (blocked by Outlook and many clients).
        // The only image is the embedded logo: nothing loads from outside.
        $this->assertSame(1, preg_match_all('/<img\b/i', $rendered['html']));
        $this->assertStringContainsString('src="cid:properx-logo"', $rendered['html']);
        $this->assertStringContainsString('alt="ProPerX Alliance"', $rendered['html']);
        $this->assertStringContainsString('#21579d', $rendered['html']); // Nova palette, inlined
    }

    public function test_the_logo_travels_inside_the_message(): void
    {
        config(['mail.default' => 'array']);
        $member = $this->member(withTwoFactor: false);

        $member->notify(new MemberInvited);

        $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $inline = collect($sent->getAttachments())->first(fn ($part) => $part->getDisposition() === 'inline');

        $this->assertNotNull($inline);
        $this->assertSame(file_get_contents(public_path('portal/img/logo-color@2x.png')), $inline->getBody());
        $this->assertSame('image/png', $inline->getMediaType().'/'.$inline->getMediaSubtype());
    }

    public function test_password_reset_email_is_branded_and_has_no_member_data(): void
    {
        Notification::fake();
        $member = $this->member(withTwoFactor: false);

        $this->postJson('/nova/password/email', ['email' => $member->email])->assertSuccessful();

        $mail = null;
        Notification::assertSentTo($member, ResetPassword::class, function (ResetPassword $notification) use ($member, &$mail) {
            $mail = $notification->toMail($member);

            return true;
        });

        $rendered = $this->render($mail);
        $this->assertBranded($rendered);
        $this->assertMatchesRegularExpression('/Regards,<br>\s*ProPerX Alliance/', $rendered['html']);
        $this->assertStringContainsString('/nova/password/reset/', $rendered['html']);

        foreach ([$member->email, $member->memberProfile->first_name, $member->memberProfile->last_name] as $value) {
            $this->assertStringNotContainsString($value, $rendered['html'].$rendered['text']);
        }
    }

    public function test_new_staff_account_email_is_branded_and_has_no_personal_data(): void
    {
        $staff = $this->staff(Role::ComplianceSpecialist);

        $rendered = $this->render((new StaffAccountCreated)->toMail($staff));

        $this->assertBranded($rendered);
        $this->assertStringContainsString('/nova/password/reset/', $rendered['html']);

        foreach ([$staff->email, $staff->name] as $value) {
            $this->assertStringNotContainsString($value, $rendered['html'].$rendered['text']);
        }
    }

    public function test_license_reminder_email_is_branded_and_has_no_member_data(): void
    {
        $member = $this->member('expiring');
        $profile = $member->memberProfile;

        $rendered = $this->render((new LicenseExpiryReminder($profile, ReminderType::Days30))->toMail($member));

        $this->assertBranded($rendered);
        $this->assertStringContainsString('ProPerX Alliance Membership Team', $rendered['html']);

        foreach ([$member->email, $profile->first_name, $profile->last_name, $profile->npn, $profile->license_number, $profile->license_expires_at->toFormattedDateString()] as $value) {
            $this->assertStringNotContainsString($value, $rendered['html'].$rendered['text']);
        }
    }

    public function test_scheduled_task_failure_email_is_branded_and_shows_ids_only(): void
    {
        $director = $this->staff(Role::ExecutiveDirector);
        $member = $this->member();

        $notification = new ScheduledTaskFailed('app:check-license-expirations', ["Member profile #{$member->memberProfile->id}: RuntimeException"]);
        $rendered = $this->render($notification->toMail($director));

        $this->assertBranded($rendered);
        $this->assertMatchesRegularExpression('/Regards,<br>\s*ProPerX Alliance/', $rendered['html']);
        $this->assertStringContainsString("Member profile #{$member->memberProfile->id}", $rendered['html']);
        $this->assertStringNotContainsString($member->memberProfile->last_name, $rendered['html'].$rendered['text']);
    }

    public function test_app_name_defaults_to_the_brand(): void
    {
        $this->assertStringContainsString("env('APP_NAME', 'ProPerX Alliance')", file_get_contents(config_path('app.php')));
    }
}
