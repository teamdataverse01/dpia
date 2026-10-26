<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AssessmentStatus;
use App\Infrastructure\AuditLogger;
use App\Repositories\RiskEntityRepository;
use App\Support\ConflictException;

/** Applies the assessment status lifecycle (PRD 6) and audits every transition. */
final class StatusTransitioner
{
    public function __construct(
        private readonly RiskEntityRepository $entities,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string,mixed> $extra extra columns to update with the status */
    public function to(string $actor, array $entity, AssessmentStatus $next, array $extra = []): void
    {
        $current = AssessmentStatus::from($entity['status']);
        if ($current === $next && $extra === []) {
            return;
        }
        if (!$current->canTransitionTo($next)) {
            throw new ConflictException(sprintf(
                'This assessment is "%s" and cannot move to "%s".',
                $current->label(),
                $next->label(),
            ));
        }
        $this->entities->update((int) $entity['id'], ['status' => $next->value] + $extra);
        if ($current !== $next) {
            $this->audit->record($actor, 'risk_entities', (int) $entity['id'], 'status_change', ['status' => $current->value], ['status' => $next->value]);
        }
    }

    public static function assertEditable(array $entity): void
    {
        $s = AssessmentStatus::from($entity['status']);
        if (!$s->isEditable()) {
            throw new ConflictException(sprintf(
                'This assessment is "%s", so it cannot be changed now.%s',
                $s->label(),
                $s === AssessmentStatus::PendingApproval ? ' Wait for the approval decision.' : ' Start a reassessment to make changes.',
            ));
        }
    }
}
