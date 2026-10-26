<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string,string> */
    public array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body form fields or decoded JSON
     * @param array<string,mixed> $files
     * @param array<string,string> $headers lower-case names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        public readonly array $headers,
        public readonly bool $isJson,
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($basePath !== '' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath)) ?: '/';
        }
        $path = '/' . trim(rawurldecode($uri), '/');

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $isJson = str_contains($headers['content-type'] ?? '', 'application/json');
        $body = $_POST;
        if ($isJson) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }
        return new self($method, $path, $_GET, $body, $_FILES, $headers, $isJson);
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function param(string $key): string
    {
        return $this->params[$key] ?? '';
    }

    public function intParam(string $key): int
    {
        return (int) ($this->params[$key] ?? 0);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** A single uploaded file or null when none was sent. */
    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
            return null;
        }
        if ($f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new \App\Support\ValidationException(
                $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'The file is too large.' : 'The upload failed. Please try again.',
                [$key => 'Upload failed.'],
            );
        }
        return $f;
    }
}
