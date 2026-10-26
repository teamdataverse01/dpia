<?php

declare(strict_types=1);

namespace App\Domain;

/** Stored statuses. "overdue" is derived (past target_date and not completed/cancelled), never stored. */
enum RemediationStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public static function displayStatus(string $stored, string $targetDate, \DateTimeImmutable $today): string
    {
        $closed = in_array($stored, [self::Completed->value, self::Cancelled->value], true);
        if (!$closed && $targetDate < $today->format('Y-m-d')) {
            return 'overdue';
        }
        return $stored;
    }
}
