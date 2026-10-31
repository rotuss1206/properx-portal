<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * Storage answers, but the record's file is not there: retrying will not help, so the member
 * is sent to support (404). Logged as a warning with the kind and id only.
 */
class DocumentMissingException extends DocumentDownloadException
{
    protected const LOG_MESSAGE = 'Document file missing from storage';

    public function heading(): string
    {
        return 'Document not available';
    }

    public function userMessage(): string
    {
        return 'This document is not available right now. Please contact ProPerx support.';
    }

    protected function status(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
