<?php

namespace App\Services\Documents;

final readonly class StoredDocument
{
    public function __construct(
        public string $path,
        public string $originalName,
        public string $mimeType,
        public int $size,
    ) {}
}
