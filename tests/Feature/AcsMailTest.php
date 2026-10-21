<?php

namespace Tests\Feature;

use App\Mail\AcsTransportFactory;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Symfony\Component\Mailer\Bridge\Azure\Transport\AzureApiTransport;
use Tests\TestCase;

/**
 * Mail goes through Azure Communication Services (KEENEY platform rule).
 */
class AcsMailTest extends TestCase
{
    private const CONNECTION_STRING = 'endpoint=https://properx-acs.unitedstates.communication.azure.com/;accesskey=dGVzdC1rZXk=';

    public function test_transport_is_built_from_the_acs_connection_string(): void
    {
        $transport = AcsTransportFactory::fromConnectionString(self::CONNECTION_STRING);

        $this->assertInstanceOf(AzureApiTransport::class, $transport);
        $this->assertSame('azure+api://properx-acs.unitedstates.communication.azure.com', (string) $transport);
    }

    public function test_connection_string_keys_are_case_insensitive(): void
    {
        $transport = AcsTransportFactory::fromConnectionString('Endpoint=https://acs-x.communication.azure.com/;AccessKey=abc');

        $this->assertSame('azure+api://acs-x.communication.azure.com', (string) $transport);
    }

    public function test_invalid_connection_string_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AcsTransportFactory::fromConnectionString('endpoint=https://acs-x.communication.azure.com/');
    }

    public function test_azure_is_the_default_mailer_and_uses_platform_settings(): void
    {
        config([
            'mail.mailers.azure.connection_string' => self::CONNECTION_STRING,
            'mail.from.address' => 'portal@properx.org',
        ]);

        // The platform does not set MAIL_MAILER, so ACS must be the default (phpunit.xml overrides it).
        $this->assertStringContainsString("env('MAIL_MAILER', 'azure')", file_get_contents(config_path('mail.php')));
        $this->assertInstanceOf(AzureApiTransport::class, Mail::mailer('azure')->getSymfonyTransport());
    }
}
