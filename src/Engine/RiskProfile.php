<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\EntityType;
use App\Domain\Rating;

/** The facts about an assessment that control `risk_trigger` rules are evaluated against. */
final class RiskProfile
{
    /**
     * @param array<string,int> $factorScores factor value => 1–5 score (active factors only)
     * @param list<string> $dataTypes
     */
    public function __construct(
        public readonly EntityType $entityType,
        public readonly array $factorScores,
        public readonly bool $involvesDataSharing,
        public readonly Rating $inherentRating,
        public readonly array $dataTypes = [],
    ) {
    }
}
