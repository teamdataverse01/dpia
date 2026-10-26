<?php

declare(strict_types=1);

namespace App\Domain;

/** The scored risk factors (PRD 8.1). `data_type` is captured but not scored. */
enum Factor: string
{
    case DataClassification = 'data_classification';
    case DataVolume = 'data_volume';
    case AutomatedDecisionMaking = 'automated_decision_making';
    case CrossBorderTransfer = 'cross_border_transfer';
    case VendorRisk = 'vendor_risk';
    case ThirdPartyTransfer = 'third_party_transfer';
    case NatureOfProcessing = 'nature_of_processing';
    case DataSubjectType = 'data_subject_type';
    case LegalBasis = 'legal_basis';

    public function label(): string
    {
        return match ($this) {
            self::DataClassification => 'Data classification / sensitivity',
            self::DataVolume => 'Data volume',
            self::AutomatedDecisionMaking => 'Automated decision-making',
            self::CrossBorderTransfer => 'Cross-border transfer',
            self::VendorRisk => 'Vendor risk score',
            self::ThirdPartyTransfer => 'Third-party transfer',
            self::NatureOfProcessing => 'Nature of processing',
            self::DataSubjectType => 'Data subject type',
            self::LegalBasis => 'Legal basis',
        };
    }

    /** Short phrase used in dashboard stories. */
    public function storyPhrase(): string
    {
        return match ($this) {
            self::DataClassification => 'sensitive data',
            self::DataVolume => 'large data volumes',
            self::AutomatedDecisionMaking => 'automated decision-making',
            self::CrossBorderTransfer => 'cross-border transfers',
            self::VendorRisk => 'vendor integrations',
            self::ThirdPartyTransfer => 'third-party data sharing',
            self::NatureOfProcessing => 'the nature of processing',
            self::DataSubjectType => 'vulnerable data subjects',
            self::LegalBasis => 'weak legal basis',
        };
    }

    /** Factors whose score comes from a selected factor_options row (all except vendor_risk). */
    public function isOptionBased(): bool
    {
        return $this !== self::VendorRisk;
    }

    /** @return list<self> */
    public static function optionBased(): array
    {
        return array_values(array_filter(self::cases(), fn (self $f) => $f->isOptionBased()));
    }
}
