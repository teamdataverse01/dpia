<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Factor;
use App\Engine\FactorSelection;
use App\Engine\ScoringConfig;

final class Fixtures
{
    public static function config(array $overrides = [], int $version = 1): ScoringConfig
    {
        return ScoringConfig::fromArray(array_replace_recursive(ScoringConfig::defaults(), $overrides), $version);
    }

    /**
     * PRD 8.5 — "Processing Student Applications".
     *
     * @return list<FactorSelection>
     */
    public static function studentApplications(bool $withVendor = true): array
    {
        $s = [
            new FactorSelection(Factor::DataClassification, 'confidential', 4),
            new FactorSelection(Factor::DataVolume, '10000_plus', 5),
            new FactorSelection(Factor::AutomatedDecisionMaking, 'largely_automated', 4),
            new FactorSelection(Factor::CrossBorderTransfer, 'adequate_safeguards', 2),
            new FactorSelection(Factor::ThirdPartyTransfer, 'several_external', 4),
            new FactorSelection(Factor::NatureOfProcessing, 'profiling_monitoring', 4),
            new FactorSelection(Factor::DataSubjectType, 'prospective_students_minors', 5),
            new FactorSelection(Factor::LegalBasis, 'consent', 3),
        ];
        if ($withVendor) {
            $s[] = new FactorSelection(Factor::VendorRisk, 'vendor_61_80', 4);
        }
        return $s;
    }

    /** @return list<FactorSelection> */
    public static function uniform(int $score, bool $withVendor, bool $withLegal = true): array
    {
        $out = [];
        foreach (Factor::cases() as $f) {
            if ($f === Factor::VendorRisk && !$withVendor) {
                continue;
            }
            if ($f === Factor::LegalBasis && !$withLegal) {
                continue;
            }
            $out[] = new FactorSelection($f, 'opt' . $score, $score);
        }
        return $out;
    }
}
