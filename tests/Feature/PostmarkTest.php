<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\ReminderType;
use App\Models\ReminderLog;
use App\Notifications\LicenseExpiryReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\Concerns\CreatesPortalUsers;
use Tests\TestCase;

/**
 * Postmark sending and the bounce / spam complaint webhook (Spec 3.4).
 */
class PostmarkTest extends TestCase
{
    use CreatesPortalUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        config([
            'services.postmark.webhook.username' => 'postmark',
            'services.postmark.webhook.password' => 's3cret',
        ]);
    }

    private function reminderLog(DeliveryStatus $status = DeliveryStatus::Sent): ReminderLog
    {
        return ReminderLog::factory()->for($this->member())->create(['delivery_status' => $status]);
    }

    private function webhook(array $payload, string $password = 's3cret')
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode("postmark:{$password}"))
            ->postJson(route('webhooks.postmark'), $payload);
    }

    public function test_postmark_mailer_uses_the_token_from_env(): void
    {
        config(['services.postmark.token' => 'POSTMARK_API_TEST']);

        $transport = Mail::mailer('postmark')->getSymfonyTransport();

        $this->assertInstanceOf(TransportInterface::class, $transport);
        $this->assertStringStartsWith('postmark+api://', (string) $transport);
    }

    public function test_reminder_carries_tag_and_reminder_log_metadata(): void
    {
        $member = $this->member();
        $log = ReminderLog::factory()->for($member)->create();

        $member->notifyNow(new LicenseExpiryReminder($member->memberProfile, ReminderType::Days30, $log->id));

        $headers = collect(Mail::mailer('array')->getSymfonyTransport()->messages())->last()->getOriginalMessage()->getHeaders();

        $metadata = collect($headers->all())->first(fn ($header) => $header instanceof MetadataHeader);
        $tag = collect($headers->all())->first(fn ($header) => $header instanceof TagHeader);

        $this->assertSame('reminder_log_id', $metadata?->getKey());
        $this->assertSame((string) $log->id, $metadata->getValue());
        $this->assertSame(LicenseExpiryReminder::TAG, $tag?->getValue());
    }

    public function test_webhook_requires_basic_auth(): void
    {
        $log = $this->reminderLog();
        $payload = ['RecordType' => 'Bounce', 'Metadata' => ['reminder_log_id' => (string) $log->id]];

        $this->postJson(route('webhooks.postmark'), $payload)->assertUnauthorized();
        $this->webhook($payload, password: 'wrong')->assertUnauthorized();

        $this->assertSame(DeliveryStatus::Sent, $log->fresh()->delivery_status);
    }

    public function test_webhook_is_closed_until_credentials_are_configured(): void
    {
        config(['services.postmark.webhook.username' => null, 'services.postmark.webhook.password' => null]);

        $this->withHeader('Authorization', 'Basic '.base64_encode(':'))
            ->postJson(route('webhooks.postmark'), ['RecordType' => 'Bounce'])
            ->assertUnauthorized();
    }

    public function test_bounce_and_spam_complaint_update_the_reminder_log(): void
    {
        $bounced = $this->reminderLog();
        $complained = $this->reminderLog();

        $this->webhook([
            'RecordType' => 'Bounce',
            'Type' => 'HardBounce',
            'MessageID' => 'abc',
            'Email' => 'member@example.com',
            'Metadata' => ['reminder_log_id' => (string) $bounced->id],
        ])->assertOk()->assertJson(['handled' => true]);

        $this->webhook([
            'RecordType' => 'SpamComplaint',
            'Metadata' => ['reminder_log_id' => (string) $complained->id],
        ])->assertOk();

        $this->assertSame(DeliveryStatus::Bounced, $bounced->fresh()->delivery_status);
        $this->assertSame(DeliveryStatus::Complained, $complained->fresh()->delivery_status);
    }

    public function test_delivery_marks_delivered_but_never_downgrades(): void
    {
        $sent = $this->reminderLog();
        $complained = $this->reminderLog(DeliveryStatus::Complained);

        foreach ([$sent, $complained] as $log) {
            $this->webhook(['RecordType' => 'Delivery', 'Metadata' => ['reminder_log_id' => (string) $log->id]])->assertOk();
        }

        $this->assertSame(DeliveryStatus::Delivered, $sent->fresh()->delivery_status);
        $this->assertSame(DeliveryStatus::Complained, $complained->fresh()->delivery_status);
    }

    public function test_unknown_events_are_acknowledged_without_changes(): void
    {
        $log = $this->reminderLog();

        $this->webhook(['RecordType' => 'Open', 'Metadata' => ['reminder_log_id' => (string) $log->id]])
            ->assertOk()
            ->assertJson(['handled' => false]);
        $this->webhook(['RecordType' => 'Bounce', 'Metadata' => ['reminder_log_id' => '999999']])
            ->assertOk()
            ->assertJson(['handled' => false]);

        $this->assertSame(DeliveryStatus::Sent, $log->fresh()->delivery_status);
    }
}
