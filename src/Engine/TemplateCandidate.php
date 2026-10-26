<?php

declare(strict_types=1);

namespace App\Engine;

/** The fields of an activity_templates row the matcher and staleness check need. */
final class TemplateCandidate
{
    public function __construct(
        public readonly int $id,
        public readonly int $departmentId,
        public readonly string $departmentName,
        public readonly string $activityName,
        public readonly string $dataTypeSignature,
        public readonly string $dataSubjectSignature,
        public readonly string $characteristicsSignature,
        public readonly int $frameworkVersion,
        public readonly string $lastReviewedAt, // Y-m-d
    ) {
    }
}
