<?php

declare(strict_types=1);

namespace App\Domain;

enum EntityType: string
{
    case Vendor = 'vendor';
    case ProcessingActivity = 'processing_activity';

    public function label(): string
    {
        return match ($this) {
            self::Vendor => 'Vendor',
            self::ProcessingActivity => 'DPIA',
        };
    }

    public function longLabel(): string
    {
        return match ($this) {
            self::Vendor => 'Vendor Risk Assessment',
            self::ProcessingActivity => 'Data Protection Impact Assessment (DPIA)',
        };
    }
}
