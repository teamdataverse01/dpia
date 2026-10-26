<?php

declare(strict_types=1);

namespace App\Domain;

enum ControlStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Implemented = 'implemented';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Implemented => 'Implemented',
            self::NotApplicable => 'Not applicable',
        };
    }
}
