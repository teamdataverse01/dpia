<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\EvidenceStorage;
use App\Contracts\StoredEvidence;
use App\Support\ValidationException;

/**
 * Stores evidence on local disk OUTSIDE the web root, under random file names.
 * Allow-lists both the extension and the detected MIME type, and caps the size (PRD 15).
 */
final class LocalEvidenceStorage implements EvidenceStorage
{
    /** extension => allowed detected MIME types */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    public function __construct(private readonly string $root, private readonly int $maxBytes)
    {
    }

    public function store(string $tmpPath, string $originalName, int $riskEntityId): StoredEvidence
    {
        if (!is_file($tmpPath)) {
            throw new ValidationException('The upload did not arrive. Please try again.', ['evidence' => 'Upload failed.']);
        }
        $bytes = (int) filesize($tmpPath);
        if ($bytes === 0) {
            throw new ValidationException('The file is empty.', ['evidence' => 'The file is empty.']);
        }
        if ($bytes > $this->maxBytes) {
            throw new ValidationException(
                sprintf('The file is too large. The limit is %d MB.', intdiv($this->maxBytes, 1048576)),
                ['evidence' => 'File too large.'],
            );
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            throw new ValidationException(
                'This file type is not allowed. Upload PDF, Word (.docx), Excel (.xlsx), image (PNG/JPG), CSV or text files.',
                ['evidence' => 'File type not allowed.'],
            );
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream';
        if (!in_array($mime, self::ALLOWED[$ext], true)) {
            throw new ValidationException(
                'The file content does not match its extension, so it was rejected.',
                ['evidence' => 'File content does not match its type.'],
            );
        }

        $dir = $this->root . DIRECTORY_SEPARATOR . $riskEntityId;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Evidence storage is not writable.');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = $dir . DIRECTORY_SEPARATOR . $stored;
        $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $target) : rename($tmpPath, $target);
        if (!$moved) {
            throw new \RuntimeException('Could not save the evidence file.');
        }
        chmod($target, 0640);

        $safeName = preg_replace('/[^\w.\- ()]+/u', '_', basename($originalName)) ?: 'evidence.' . $ext;
        return new StoredEvidence($riskEntityId . '/' . $stored, mb_substr($safeName, 0, 255), $mime, $bytes);
    }

    public function open(string $key): ?string
    {
        if (!preg_match('#^\d+/[a-f0-9]{32}\.[a-z]{3,4}$#', $key)) {
            return null; // never resolve user-influenced paths
        }
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
        return is_file($path) ? $path : null;
    }

    public function delete(string $key): void
    {
        $path = $this->open($key);
        if ($path !== null) {
            unlink($path);
        }
    }
}
