<?php

declare(strict_types=1);

namespace App\Support;

/** A user-correctable problem with submitted data. Rendered as 422 (API) or inline form errors (web). */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $fields field => plain-language message */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
