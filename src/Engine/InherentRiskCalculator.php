<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\EntityType;
use App\Domain\Factor;

/**
 * PRD 8.3 — weighted average of 1–5 factor scores, with the weights of excluded factors
 * removed and the remainder rescaled to 100%. Pure: no I/O.
 */
final class InherentRiskCalculator
{
    /**
     * Factors that take part in the calculation for this entity (PRD 7.1, 8.1, 8.3).
     *
     * @return array{active: list<Factor>, excluded: list<Factor>, reasons: array<string,string>}
     */
    public function activeFactors(EntityType $type, bool $involvesDataSharing): array
    {
        $active = [];
        $excluded = [];
        $reasons = [];
        foreach (Factor::cases() as $f) {
            if ($f === Factor::VendorRisk && $type === EntityType::Vendor) {
                $excluded[] = $f;
                $reasons[$f->value] = 'Vendor assessments never include a vendor-risk factor.';
                continue;
            }
            if ($f === Factor::LegalBasis && $type === EntityType::Vendor) {
                $excluded[] = $f;
                $reasons[$f->value] = 'Legal basis is supplied by the processing activity, not asked of vendors.';
                continue;
            }
            if ($f === Factor::VendorRisk && !$involvesDataSharing) {
                $excluded[] = $f;
                $reasons[$f->value] = 'This activity does not involve sharing data, so vendor risk is not part of the calculation.';
                continue;
            }
            $active[] = $f;
        }
        return ['active' => $active, 'excluded' => $excluded, 'reasons' => $reasons];
    }

    /**
     * @param array<string,FactorSelection>|list<FactorSelection> $selections
     * @throws \InvalidArgumentException when a required factor is missing or an excluded one is supplied
     */
    public function calculate(
        EntityType $type,
        bool $involvesDataSharing,
        array $selections,
        ScoringConfig $config,
    ): InherentRiskResult {
        $byFactor = [];
        foreach ($selections as $s) {
            $byFactor[$s->factor->value] = $s;
        }

        $plan = $this->activeFactors($type, $involvesDataSharing);

        foreach ($plan['excluded'] as $f) {
            if ($f === Factor::VendorRisk && isset($byFactor[$f->value])) {
                // 7.1: a vendor score must never leak into a calculation it is excluded from.
                throw new \InvalidArgumentException('A vendor risk score was supplied but the vendor factor is excluded for this assessment.');
            }
            unset($byFactor[$f->value]);
        }

        $missing = [];
        foreach ($plan['active'] as $f) {
            if (!isset($byFactor[$f->value])) {
                $missing[] = $f->value;
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException('Missing factor selections: ' . implode(', ', $missing));
        }

        $totalWeight = 0.0;
        foreach ($plan['active'] as $f) {
            $totalWeight += $config->weight($f);
        }
        if ($totalWeight <= 0.0) {
            throw new \InvalidArgumentException('Active factor weights total zero; cannot score.');
        }

        // Sum of (weight% × score) over the active factors, divided once by the active weight total.
        // Dividing once keeps the arithmetic exact for whole-number weights (e.g. 385 / 100 = 3.85).
        $numerator = 0.0;
        $breakdown = [];
        foreach ($plan['active'] as $f) {
            $sel = $byFactor[$f->value];
            $w = $config->weight($f);
            $numerator += $w * $sel->score;
            $weightUsed = $w / $totalWeight;
            $breakdown[] = new FactorContribution(
                $f,
                $sel->optionKey,
                $sel->optionLabel !== '' ? $sel->optionLabel : $sel->optionKey,
                $sel->score,
                $w,
                round($weightUsed, 4),
                round($weightUsed * $sel->score, 4),
            );
        }

        $score = round($numerator / $totalWeight, 2); // 8.4: round after the sum
        return new InherentRiskResult(
            $score,
            $config->ratingFor($score),
            $breakdown,
            $plan['excluded'],
            $plan['reasons'],
            $config->version,
            $totalWeight,
        );
    }
}
