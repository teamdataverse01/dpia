<?php

declare(strict_types=1);

namespace App\Domain;

enum ControlCategory: string
{
    case Policy = 'policy';
    case Process = 'process';
    case Technology = 'technology';

    public function label(): string
    {
        return match ($this) {
            self::Policy => 'Policy / Documents',
            self::Process => 'Process / Procedures',
            self::Technology => 'Technology / Technical',
        };
    }

    public function shortLabel(): string
    {
        return ucfirst($this->value);
    }
}
