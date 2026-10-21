<?php

namespace App\Mail;

use InvalidArgumentException;
use Symfony\Component\Mailer\Bridge\Azure\Transport\AzureApiTransport;

/**
 * Builds the Azure Communication Services mail transport (symfony/azure-mailer)
 * from an ACS connection string: "endpoint=https://<resource>.communication.azure.com/;accesskey=<key>".
 */
final class AcsTransportFactory
{
    public static function fromConnectionString(?string $connectionString): AzureApiTransport
    {
        $parts = [];

        foreach (explode(';', (string) $connectionString) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            if ($key !== '') {
                $parts[strtolower(trim($key))] = trim($value);
            }
        }

        $host = parse_url($parts['endpoint'] ?? '', PHP_URL_HOST);
        $accessKey = $parts['accesskey'] ?? '';

        if (! is_string($host) || $host === '' || $accessKey === '') {
            throw new InvalidArgumentException('KEENEY_ACS_CONNECTION_STRING must contain "endpoint=...;accesskey=...".');
        }

        $transport = new AzureApiTransport($accessKey, strstr($host, '.', true) ?: $host);
        $transport->setHost($host);

        return $transport;
    }
}
