<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\ControlCategory;
use App\Domain\ControlStatus;

/**
 * PRD 9.2 — decides which control categories are "cleared" for an assessment.
 *
 * A category is cleared when:
 *  - every recommended MANDATORY control in it meets the criteria, and
 *  - at least one recommended control in it meets the criteria (so an empty category is never cleared
 *    for free — see docs/DECISIONS.md D-07).
 *
 * Criteria for a control: status = implemented, effectiveness ≥ clearance_min_effectiveness,
 * evidence present and not expired, and verified by a Reviewer when verification_required.
 */
final class ClearanceEvaluator
{
    /** @param list<ControlAssessmentInput> $controls recommended controls with their current assessment */
    public function evaluate(array $controls, ScoringConfig $config, \DateTimeImmutable $today): ClearanceResult
    {
        $cleared = [];
        $reasons = [];
        $gaps = [];

        foreach (ControlCategory::cases() as $category) {
            $inCategory = array_values(array_filter($controls, fn (ControlAssessmentInput $c) => $c->category === $category));
            $why = [];
            $anyMeets = false;
            $mandatoryCount = 0;

            foreach ($inCategory as $c) {
                $unmet = $this->unmetCriteria($c, $config, $today);
                if ($unmet === []) {
                    $anyMeets = true;
                } else {
                    $gaps[$c->controlId] = $unmet;
                }
                if ($c->mandatory) {
                    $mandatoryCount++;
                    if ($unmet !== []) {
                        $why[] = sprintf('"%s" (mandatory): %s.', $c->title, implode('; ', $unmet));
                    }
                }
            }

            if ($inCategory === []) {
                $why[] = 'No controls in this category were recommended for this assessment.';
            } elseif (!$anyMeets && $mandatoryCount === 0) {
                $why[] = 'None of the recommended controls in this category meet the clearance criteria yet.';
            }

            if ($why === []) {
                $cleared[] = $category;
            }
            $reasons[$category->value] = $why;
        }

        return new ClearanceResult($cleared, $reasons, $gaps);
    }

    /** @return list<string> plain-language list of unmet criteria (empty = meets all) */
    public function unmetCriteria(ControlAssessmentInput $c, ScoringConfig $config, \DateTimeImmutable $today): array
    {
        $unmet = [];
        if ($c->status !== ControlStatus::Implemented) {
            $unmet[] = 'status is "' . $c->status->label() . '", not "Implemented"';
        }
        if ($c->effectivenessPct === null || $c->effectivenessPct < $config->clearanceMinEffectiveness) {
            $unmet[] = sprintf(
                'effectiveness is %s, needs at least %d%%',
                $c->effectivenessPct === null ? 'not recorded' : $c->effectivenessPct . '%',
                $config->clearanceMinEffectiveness,
            );
        }
        if (!$c->hasEvidence) {
            $unmet[] = 'no evidence uploaded';
        } elseif ($c->evidenceExpiryDate !== null && $c->evidenceExpiryDate < $today->format('Y-m-d')) {
            $unmet[] = 'evidence expired on ' . $c->evidenceExpiryDate;
        }
        if ($c->verificationRequired && !$c->verified) {
            $unmet[] = 'evidence not yet verified by a Reviewer';
        }
        return $unmet;
    }
}
