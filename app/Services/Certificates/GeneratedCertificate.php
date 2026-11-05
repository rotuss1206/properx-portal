<?php

namespace App\Services\Certificates;

final readonly class GeneratedCertificate
{
    /**
     * @param  array<string, bool|string|null>  $auditDetails  the choices behind this file, no member data
     */
    public function __construct(
        public string $disk,
        public string $path,
        public string $filename,
        public bool $fromCache,
        public array $auditDetails = [],
    ) {}
}
