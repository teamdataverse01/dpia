<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\ControlCategory;
use App\Domain\Rating;

/**
 * PRD 9.3 (rating — authoritative) and 9.4 (numeric indicator). Pure: no I/O.
 */
final class ResidualRiskCalculator
{
    /**
     * @param list<ControlCategory> $clearedCategories
     */
    public function calculate(
        Rating $inherentRating,
        float $inherentScore,
        array $clearedCategories,
        bool $negligibleConfirmed,
        ScoringConfig $config,
        bool $isVendor,
    ): ResidualRiskResult {
        $cleared = [];
        foreach (ControlCategory::cases() as $c) { // de-duplicate, stable order
            if (in_array($c, $clearedCategories, true)) {
                $cleared[] = $c;
            }
        }
        $n = count($cleared);
        $techCleared = in_array(ControlCategory::Technology, $cleared, true);
        $names = $n === 0 ? 'no control types' : implode(' + ', array_map(fn (ControlCategory $c) => $c->shortLabel(), $cleared));

        [$computed, $code, $why] = match ($inherentRating) {
            Rating::High => match (true) {
                $n === 0 => [Rating::High, 'high_none_cleared', 'Inherent risk is High and no control type is cleared yet, so residual risk stays High.'],
                $n === 1 => [Rating::Medium, 'high_one_cleared', "Inherent risk is High and one control type is cleared ({$names}), which brings it down to Medium. Reaching Low needs at least two cleared types, one of them Technology."],
                $techCleared => [Rating::Low, 'high_two_plus_with_technology', "Inherent risk is High and {$n} control types are cleared ({$names}), including Technology, so residual risk is Low."],
                default => [Rating::Medium, 'high_two_plus_without_technology', "Inherent risk is High and {$n} control types are cleared ({$names}), but Technology is not one of them, so residual risk is Medium. Clearing Technology controls would bring it to Low."],
            },
            Rating::Medium => $n === 0
                ? [Rating::Medium, 'medium_none_cleared', 'Inherent risk is Medium and no control type is cleared yet, so residual risk stays Medium. Clearing any one control type would bring it to Low.']
                : [Rating::Low, 'medium_one_plus_cleared', "Inherent risk is Medium and at least one control type is cleared ({$names}), so residual risk is Low."],
            Rating::Low, Rating::VeryLow => [Rating::Low, 'low_stays_low', 'Inherent risk is Low, so residual risk stays Low.'],
        };

        $final = $computed;
        $negligibleApplied = false;
        if ($negligibleConfirmed) {
            if ($computed === Rating::Low) {
                $final = Rating::VeryLow;
                $negligibleApplied = true;
                $why .= ' A Reviewer or Risk Approver has confirmed the remaining risk is negligible, so it is rated Very Low.';
            } else {
                $why .= ' (A negligible-risk confirmation is on record but only applies when the computed rating is Low, so it is ignored.)';
            }
        }

        // 9.4 indicator. Work in hundredths / basis points so 3.85 × 0.35 = 1.3475 rounds to 1.35 exactly.
        $reductionBp = 0;
        foreach ($cleared as $c) {
            $reductionBp += (int) round($config->reductionWeight($c) * 10000);
        }
        $reductionBp = min(10000, $reductionBp);
        $inherentCents = (int) round($inherentScore * 100);
        $residualCents = (int) round($inherentCents * (10000 - $reductionBp) / 10000);
        $residualScore = max(1.0, $residualCents / 100); // keep the indicator on the 1–5 scale
        $reductionPct = $reductionBp / 10000;

        return new ResidualRiskResult(
            $final,
            $computed,
            $code,
            $why,
            $cleared,
            $reductionPct,
            $residualScore,
            round($reductionPct * 100, 2),
            $isVendor ? VendorRiskMapper::to100($residualScore) : null,
            $negligibleApplied,
        );
    }
}
