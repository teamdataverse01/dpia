<?php

declare(strict_types=1);

namespace App\Domain;

enum Role: string
{
    case Requestor = 'requestor';
    case Vendor = 'vendor';
    case BusinessOwner = 'business_owner';
    case InfoSec = 'infosec';
    case PrivacyDpo = 'privacy_dpo';
    case Reviewer = 'reviewer';
    case Admin = 'admin';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::Requestor => 'Requestor / Activity Owner',
            self::Vendor => 'Vendor',
            self::BusinessOwner => 'Business Owner',
            self::InfoSec => 'Information Security',
            self::PrivacyDpo => 'Privacy Team / DPO',
            self::Reviewer => 'Reviewer',
            self::Admin => 'Administrator',
            self::Auditor => 'Auditor',
        };
    }
}
