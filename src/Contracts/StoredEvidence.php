<?php

declare(strict_types=1);

namespace App\Contracts;

final class StoredEvidence
{
    public function __construct(
        public readonly string $key,
        public readonly string $originalName,
        public readonly string $mime,
        public readonly int $bytes,
    ) {
    }
}
