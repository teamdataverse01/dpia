<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\Factor;
use App\Domain\Rating;

final class InherentRiskResult
{
    /**
     * @param list<FactorContribution> $breakdown
     * @param list<Factor> $excludedFactors
     * @param array<string,string> $exclusionReasons factor value => plain-language reason
     */
    public function __construct(
        public readonly float $score,
        public readonly Rating $rating,
        public readonly array $breakdown,
        public readonly array $excludedFactors,
        public readonly array $exclusionReasons,
        public readonly int $configVersion,
        public readonly float $activeWeightTotalPct,
    ) {
    }

    public function contribution(Factor $factor): ?FactorContribution
    {
        foreach ($this->breakdown as $c) {
            if ($c->factor === $factor) {
                return $c;
            }
        }
        return null;
    }

    /** Explanation payload (PRD 14). */
    public function toArray(): array
    {
        return [
            'inherent_score' => $this->score,
            'inherent_rating' => $this->rating->value,
            'inherent_rating_label' => $this->rating->label(),
            'framework_version' => $this->configVersion,
            'active_weight_total_pct' => $this->activeWeightTotalPct,
            'breakdown' => array_map(fn (FactorContribution $c) => $c->toArray(), $this->breakdown),
            'excluded_factors' => array_map(
                fn (Factor $f) => ['factor' => $f->value, 'factor_label' => $f->label(), 'reason' => $this->exclusionReasons[$f->value] ?? ''],
                $this->excludedFactors,
            ),
            'explanation' => $this->explanation(),
        ];
    }

    public function explanation(): string
    {
        $text = sprintf(
            'Each factor was scored from 1 (lowest risk) to 5 (highest) and multiplied by its weight. The weighted total is %s, which falls in the %s band.',
            number_format($this->score, 2),
            $this->rating->label(),
        );
        if ($this->excludedFactors !== []) {
            $text .= sprintf(
                ' %s %s not apply here, so the remaining weights (%s%% of the total) were scaled up to 100%%.',
                implode(' and ', array_map(fn (Factor $f) => '"' . $f->label() . '"', $this->excludedFactors)),
                count($this->excludedFactors) > 1 ? 'do' : 'does',
                rtrim(rtrim(number_format($this->activeWeightTotalPct, 2, '.', ''), '0'), '.'),
            );
        }
        return $text;
    }
}
