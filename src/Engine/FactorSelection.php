<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\Factor;

/** One chosen option for one factor, already mapped to its 1–5 score. */
final class FactorSelection
{
    public function __construct(
        public readonly Factor $factor,
        public readonly string $optionKey,
        public readonly int $score,
        public readonly string $optionLabel = '',
    ) {
        if ($score < 1 || $score > 5) {
            throw new \InvalidArgumentException("Factor score for {$factor->value} must be 1–5, got {$score}.");
        }
    }
}
