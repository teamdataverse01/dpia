<?php

declare(strict_types=1);

namespace App\Contracts;

/** Where evidence files live. CU provisions the real storage; paths returned here are opaque keys. */
interface EvidenceStorage
{
    /**
     * Store an uploaded file and return an opaque storage key (never the user's file name).
     *
     * @throws \App\Support\ValidationException when the file is not allowed (type, size)
     */
    public function store(string $tmpPath, string $originalName, int $riskEntityId): StoredEvidence;

    /** Absolute path or stream URI for reading the file back, or null if it no longer exists. */
    public function open(string $key): ?string;

    public function delete(string $key): void;
}
