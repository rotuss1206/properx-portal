<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A download that cannot be served (storage down, or the file missing from storage). The
 * log gets the kind, the record id and the underlying error's class only (no PHI); the person
 * gets a short message: inside the member area's layout for members while it is on, otherwise
 * the plain page shared with the certificate check.
 */
abstract class DocumentDownloadException extends RuntimeException
{
    protected const LOG_MESSAGE = 'Document download failed';

    public function __construct(
        public readonly string $kind,
        public readonly int|string|null $recordId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(static::LOG_MESSAGE." ({$kind}).", 0, $previous);
    }

    abstract public function heading(): string;

    abstract public function userMessage(): string;

    abstract protected function status(): int;

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [];
    }

    public function report(): void
    {
        Log::warning(static::LOG_MESSAGE, array_filter([
            'kind' => $this->kind,
            'id' => $this->recordId,
            'exception' => $this->getPrevious() ? $this->getPrevious()::class : null,
        ], fn ($value) => $value !== null));
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->userMessage()], $this->status(), $this->headers());
        }

        $user = $request->user();
        $view = config('portal.member_area.enabled') && $user !== null && $user->isMember() && ! $user->isStaff()
            ? 'member.download-error'
            : 'errors.document-download';

        return response()->view($view, [
            'heading' => $this->heading(),
            'message' => $this->userMessage(),
        ], $this->status(), $this->headers());
    }
}
