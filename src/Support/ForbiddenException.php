<?php

declare(strict_types=1);

namespace App\Support;

final class ForbiddenException extends \RuntimeException
{
    public function __construct(string $message = 'You do not have permission to do this.')
    {
        parent::__construct($message);
    }
}
