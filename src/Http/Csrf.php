<?php

declare(strict_types=1);

namespace App\Http;

/** Synchroniser-token CSRF protection for every state-changing browser request (PRD 14, 15). */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function valid(Request $request): bool
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $sent = $request->header('X-CSRF-Token') ?? ($request->body['_csrf'] ?? null);
        return is_string($sent) && isset($_SESSION[self::KEY]) && hash_equals((string) $_SESSION[self::KEY], $sent);
    }
}
