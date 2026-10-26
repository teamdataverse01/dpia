<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        public ?string $filePath = null,
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /** PRD 14 error format. */
    public static function jsonError(string $code, string $message, int $status, array $fields = []): self
    {
        $err = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $err['fields'] = $fields;
        }
        return self::json(['error' => $err], $status);
    }

    public static function redirect(string $url, int $status = 303): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function file(string $path, string $downloadName, string $mime): self
    {
        $safe = str_replace(['"', "\r", "\n"], '', $downloadName);
        return new self('', 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Content-Length' => (string) filesize($path),
            'X-Content-Type-Options' => 'nosniff',
        ], $path);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'self'; form-action 'self'",
            'Cache-Control' => 'no-store',
        ];
        foreach ($this->headers + $defaults as $k => $v) {
            header($k . ': ' . $v);
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }
}
