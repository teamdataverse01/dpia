<?php

declare(strict_types=1);

namespace App\Engine;

final class InvalidConfigException extends \InvalidArgumentException
{
    /** @param array<string,string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid scoring configuration: ' . implode(' ', $errors));
    }
}
