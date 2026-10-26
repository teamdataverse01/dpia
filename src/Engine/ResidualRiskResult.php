<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\ControlCategory;
use App\Domain\Rating;

final class ResidualRiskResult
{
    /** @param list<ControlCategory> $clearedCategories */
    public function __construct(
        public readonly Rating $rating,           // final rating (may be Very Low)
        public readonly Rating $computedRating,   // rating from the 9.3 table alone
        public readonly string $ruleCode,
        public readonly string $ruleExplanation,
        public readonly array $clearedCategories,
        public readonly float $reductionPct,      // 0..1
        public readonly float $residualScore,     // 1–5, 2 dp (indicator only)
        public readonly float $riskReducedPct,    // 0..100
        public readonly ?int $residualScore100,   // vendors only
        public readonly bool $negligibleApplied,
    ) {
    }

    public function toArray(): array
    {
        return [
            'residual_rating' => $this->rating->value,
            'residual_rating_label' => $this->rating->label(),
            'computed_rating' => $this->computedRating->value,
            'rule_applied' => $this->ruleCode,
            'rule_explanation' => $this->ruleExplanation,
            'cleared_categories' => array_map(fn (ControlCategory $c) => $c->value, $this->clearedCategories),
            'reduction_pct' => $this->reductionPct,
            'residual_score' => $this->residualScore,
            'risk_reduced_pct' => $this->riskReducedPct,
            'residual_score_100' => $this->residualScore100,
            'negligible_applied' => $this->negligibleApplied,
            'note' => 'The rating comes only from the clearance rules. The residual score is an indicator for trends and never overrides the rating.',
        ];
    }
}
