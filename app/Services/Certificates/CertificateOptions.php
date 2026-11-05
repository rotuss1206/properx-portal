<?php

namespace App\Services\Certificates;

use Illuminate\Http\Request;

/**
 * What the member chose on /member/certificate. The defaults are the mockup's, also for links without
 * any choice (the dashboard's documents, Nova): member ID and join date on, QR off, the current term.
 * The verification number is always printed.
 */
final readonly class CertificateOptions
{
    public function __construct(
        public bool $memberId = true,
        public bool $joinDate = true,
        public bool $qr = false,
        public ?string $termEnd = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $term = $request->query('term');

        return new self(
            $request->boolean('member_id', true),
            $request->boolean('join_date', true),
            $request->boolean('qr', false),
            is_string($term) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $term) ? $term : null,
        );
    }

    /**
     * For the signed download link.
     *
     * @return array<string, int|string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'member_id' => (int) $this->memberId,
            'join_date' => (int) $this->joinDate,
            'qr' => (int) $this->qr,
            'term' => $this->termEnd,
        ], fn ($value) => $value !== null);
    }

    /**
     * For the audit log: choices only, no member data.
     *
     * @return array<string, bool|string|null>
     */
    public function auditDetails(?string $term): array
    {
        return [
            'member_id' => $this->memberId,
            'join_date' => $this->joinDate,
            'qr' => $this->qr,
            'term' => $term,
            'format' => 'pdf',
        ];
    }
}
