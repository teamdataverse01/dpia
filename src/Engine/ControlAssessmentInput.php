<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\ControlCategory;
use App\Domain\ControlStatus;

/** Everything the ClearanceEvaluator needs to know about one recommended control on one assessment. */
final class ControlAssessmentInput
{
    public function __construct(
        public readonly int $controlId,
        public readonly string $title,
        public readonly ControlCategory $category,
        public readonly bool $mandatory,
        public readonly ControlStatus $status,
        public readonly ?int $effectivenessPct,
        public readonly bool $hasEvidence,
        public readonly ?string $evidenceExpiryDate, // Y-m-d or null (no expiry)
        public readonly bool $verificationRequired,
        public readonly bool $verified,
    ) {
    }
}
