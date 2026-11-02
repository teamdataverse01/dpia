<?php

declare(strict_types=1);

namespace App\Domain;

enum Role: string
{
    case Vendor = 'vendor';
    case BusinessOwner = 'business_owner';
    case Reviewer = 'reviewer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Vendor => 'Vendor',
            self::BusinessOwner => 'CU Activity Owner',
            self::Reviewer => 'Reviewer',
            self::Admin => 'DPO / Administrator',
        };
    }
}
