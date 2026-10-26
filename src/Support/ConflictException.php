<?php

declare(strict_types=1);

namespace App\Support;

/** The request is valid but not allowed in the record's current state (e.g. editing while pending approval). */
final class ConflictException extends \RuntimeException
{
}
