<?php

declare(strict_types=1);

namespace App\Domain;

enum Rating: string
{
    case VeryLow = 'very_low';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::VeryLow => 'Very Low',
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }

    /** Higher rank = higher risk. */
    public function rank(): int
    {
        return match ($this) {
            self::VeryLow => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    /** Ratings in display order, highest risk first. */
    public static function displayOrder(): array
    {
        return [self::High, self::Medium, self::Low, self::VeryLow];
    }
}
