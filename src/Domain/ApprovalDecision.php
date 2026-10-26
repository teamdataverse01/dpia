<?php

declare(strict_types=1);

namespace App\Domain;

enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RiskAccepted = 'risk_accepted';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approve',
            self::Rejected => 'Reject',
            self::RiskAccepted => 'Accept the risk (time-boxed)',
        };
    }
}
