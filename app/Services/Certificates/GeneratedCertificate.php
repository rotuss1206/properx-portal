<?php

namespace App\Services\Certificates;

final readonly class GeneratedCertificate
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $filename,
        public bool $fromCache,
    ) {}
}
