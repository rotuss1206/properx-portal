<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Document storage did not answer (App\Services\Documents\StorageHealth, or a storage call
 * failed): a 503 "try again in a few minutes" (no technical detail on screen).
 */
class StorageUnavailableException extends DocumentDownloadException
{
    protected const LOG_MESSAGE = 'Document storage unavailable';

    public function heading(): string
    {
        return 'Temporarily unavailable';
    }

    public function userMessage(): string
    {
        return $this->kind === 'certificate'
            ? 'The certificate is temporarily unavailable. Please try again in a few minutes.'
            : 'The document is temporarily unavailable. Please try again in a few minutes.';
    }

    protected function status(): int
    {
        return Response::HTTP_SERVICE_UNAVAILABLE;
    }

    protected function headers(): array
    {
        return ['Retry-After' => '120'];
    }
}
