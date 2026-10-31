<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Document storage did not answer (App\Services\Documents\StorageHealth, or a storage call
 * failed). The member gets a short "try again in a few minutes" page (503); the log gets the
 * kind, the record id and the error's class only (no PHI, no technical detail on screen).
 */
class StorageUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly int|string|null $recordId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Document storage unavailable ({$kind}).", 0, $previous);
    }

    public function report(): void
    {
        Log::warning('Document storage unavailable', array_filter([
            'kind' => $this->kind,
            'id' => $this->recordId,
            'exception' => $this->getPrevious() ? $this->getPrevious()::class : null,
        ], fn ($value) => $value !== null));
    }

    public function render(Request $request): Response
    {
        $message = $this->kind === 'certificate'
            ? 'The certificate is temporarily unavailable. Please try again in a few minutes.'
            : 'The document is temporarily unavailable. Please try again in a few minutes.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE)
            : response()->view('errors.storage-unavailable', ['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '120']);
    }
}
