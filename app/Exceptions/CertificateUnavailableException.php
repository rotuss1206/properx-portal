<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The member is not eligible for a certificate; the message is safe to show to users.
 */
class CertificateUnavailableException extends RuntimeException
{
    public static function licenseExpired(?CarbonInterface $expiredAt): self
    {
        return new self($expiredAt
            ? "A certificate cannot be issued: the license expired on {$expiredAt->toFormattedDateString()}."
            : 'A certificate cannot be issued: the license has expired.');
    }

    public static function membershipInactive(): self
    {
        return new self('A certificate cannot be issued while the membership is inactive. Please settle the outstanding membership dues.');
    }

    /**
     * @param  list<string>  $fields  Human-readable field names.
     */
    public static function missingFields(array $fields): self
    {
        return new self('A certificate cannot be issued until the profile is complete. Missing: '.implode(', ', $fields).'.');
    }
}
