<?php

declare(strict_types=1);

namespace App\Support;

/** Reads .env-style configuration. Real environment variables win over the .env file. */
final class Config
{
    /** @param array<string,string> $values */
    private function __construct(private array $values)
    {
    }

    public static function load(string $envFile): self
    {
        $values = [];
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $v = trim($v);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $values[trim($k)] = $v;
            }
        }
        foreach ($values as $k => $_) {
            $env = getenv($k);
            if ($env !== false) {
                $values[$k] = $env;
            }
        }
        return new self($values);
    }

    /** @param array<string,string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }

    public function require(string $key): string
    {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            throw new \RuntimeException("Missing required configuration value {$key}.");
        }
        return $v;
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    public function isDev(): bool
    {
        return $this->get('APP_ENV', 'prod') === 'dev';
    }
}
