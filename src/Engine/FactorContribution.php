<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\Factor;

/** One row of the inherent-risk breakdown (stored in factor_score_breakdown and shown to the user). */
final class FactorContribution
{
    public function __construct(
        public readonly Factor $factor,
        public readonly string $selectedOption,
        public readonly string $optionLabel,
        public readonly int $score,
        public readonly float $configuredWeightPct,
        public readonly float $weightUsed,   // rescaled fraction, 4 dp
        public readonly float $weightedValue, // weightUsed × score, 4 dp
    ) {
    }

    public function toArray(): array
    {
        return [
            'factor' => $this->factor->value,
            'factor_label' => $this->factor->label(),
            'selected_option' => $this->selectedOption,
            'option_label' => $this->optionLabel,
            'score' => $this->score,
            'configured_weight_pct' => $this->configuredWeightPct,
            'weight_used' => $this->weightUsed,
            'weighted_value' => $this->weightedValue,
        ];
    }
}
