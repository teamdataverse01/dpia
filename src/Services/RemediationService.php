<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\RemediationStatus;
use App\Infrastructure\AuditLogger;
use App\Infrastructure\PdoConnection;
use App\Repositories\RemediationRepository;
use App\Support\Clock;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/** Stage 7 — gaps become tracked actions with owners and dates (PRD 6, 11.3). */
final class RemediationService
{
    public function __construct(
        private readonly PdoConnection $db,
        private readonly RemediationRepository $repo,
        private readonly AssessmentService $assessments,
        private readonly Authorizer $authz,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    public function list(PortalUser $user, array $filters): array
    {
        $scope = $this->authz->listScope($user) + ['user_id' => $user->id];
        $rows = $this->repo->list($filters, $scope, $this->clock->today()->format('Y-m-d'));
        return array_map(fn ($r) => $this->decorate($r), $rows);
    }

    public function decorate(array $r): array
    {
        $r['display_status'] = RemediationStatus::displayStatus($r['status'], substr((string) $r['target_date'], 0, 10), $this->clock->today());
        $r['days_overdue'] = $r['display_status'] === 'overdue'
            ? (int) (new \DateTimeImmutable(substr((string) $r['target_date'], 0, 10)))->diff($this->clock->today())->days
            : 0;
        return $r;
    }

    /** @return array{open:int,in_progress:int,overdue:int,completed:int,cancelled:int,total:int} */
    public static function summary(array $decoratedRows): array
    {
        $s = ['open' => 0, 'in_progress' => 0, 'overdue' => 0, 'completed' => 0, 'cancelled' => 0, 'total' => 0];
        foreach ($decoratedRows as $r) {
            $s[$r['display_status']]++;
            $s['total']++;
        }
        return $s;
    }

    public function create(PortalUser $user, array $in): int
    {
        $entityId = (int) ($in['risk_entity_id'] ?? 0);
        $e = $this->assessments->get($entityId);
        $this->authz->authorize($user, P::ManageRemediation, $e);
        $v = $this->validate($in, true);
        $v['risk_entity_id'] = $entityId;

        return $this->db->transaction(function () use ($user, $v) {
            $id = $this->repo->create($v);
            $this->audit->record($user->id, 'remediation_actions', $id, 'create', null, $v);
            return $id;
        });
    }

    public function update(PortalUser $user, int $id, array $in): void
    {
        $action = $this->repo->find($id);
        if ($action === null) {
            throw new NotFoundException('Remediation action not found.');
        }
        $entity = $this->assessments->get((int) $action['risk_entity_id']);
        $isActionOwner = $action['owner_portal_user_id'] === $user->id;
        if (!$isActionOwner && !$this->authz->can($user, P::ManageRemediation, $entity)) {
            throw new ForbiddenException();
        }
        $v = $this->validate($in, false);
        if ($isActionOwner && !$this->authz->can($user, P::ManageRemediation, $entity)) {
            // the person assigned can report progress, not re-plan the action
            $v = array_intersect_key($v, array_flip(['status', 'completion_pct']));
        }
        if (isset($v['status'])) {
            if ($v['status'] === RemediationStatus::Completed->value) {
                $v['completion_pct'] = 100;
                $v['completed_at'] = $this->clock->now()->format('Y-m-d H:i:s');
            } elseif ($action['status'] === RemediationStatus::Completed->value) {
                $v['completed_at'] = null;
            }
        }
        if ($v === []) {
            return;
        }
        $this->db->transaction(function () use ($user, $id, $action, $v) {
            $this->repo->update($id, $v);
            $this->audit->record($user->id, 'remediation_actions', $id, 'update', array_intersect_key($action, $v), ['risk_entity_id' => (int) $action['risk_entity_id']] + $v);
        });
    }

    private function validate(array $in, bool $creating): array
    {
        $errors = [];
        $v = [];
        if ($creating || array_key_exists('gap_description', $in)) {
            $g = trim((string) ($in['gap_description'] ?? ''));
            if ($g === '') {
                $errors['gap_description'] = 'Describe the gap and what needs to be done.';
            }
            $v['gap_description'] = $g;
        }
        if ($creating || array_key_exists('owner_portal_user_id', $in)) {
            $o = mb_substr(trim((string) ($in['owner_portal_user_id'] ?? '')), 0, 100);
            if ($o === '') {
                $errors['owner_portal_user_id'] = 'Say who owns this action.';
            }
            $v['owner_portal_user_id'] = $o;
        }
        if ($creating || array_key_exists('priority', $in)) {
            $p = (string) ($in['priority'] ?? '');
            if (!in_array($p, ['low', 'medium', 'high'], true)) {
                $errors['priority'] = 'Choose a priority.';
            }
            $v['priority'] = $p;
        }
        if ($creating || array_key_exists('target_date', $in)) {
            $d = trim((string) ($in['target_date'] ?? ''));
            if (!AssessmentService::isDate($d)) {
                $errors['target_date'] = 'Enter a target date (YYYY-MM-DD).';
            }
            $v['target_date'] = $d;
        }
        if (array_key_exists('control_id', $in) && $in['control_id'] !== '' && $in['control_id'] !== null) {
            $v['control_id'] = (int) $in['control_id'];
        }
        if (array_key_exists('status', $in)) {
            if (RemediationStatus::tryFrom((string) $in['status']) === null) {
                $errors['status'] = 'Choose a valid status.';
            }
            $v['status'] = (string) $in['status'];
        }
        if (array_key_exists('completion_pct', $in) && $in['completion_pct'] !== '') {
            $c = (string) $in['completion_pct'];
            if (!ctype_digit($c) || (int) $c > 100) {
                $errors['completion_pct'] = 'Completion must be 0–100.';
            }
            $v['completion_pct'] = (int) $c;
        }
        if (array_key_exists('reviewer_portal_user_id', $in)) {
            $v['reviewer_portal_user_id'] = mb_substr(trim((string) $in['reviewer_portal_user_id']), 0, 100) ?: null;
        }
        if (array_key_exists('approval_status', $in)) {
            if (!in_array($in['approval_status'], ['pending', 'approved', 'rejected'], true)) {
                $errors['approval_status'] = 'Choose a valid review status.';
            }
            $v['approval_status'] = (string) $in['approval_status'];
        }
        if ($errors !== []) {
            throw new ValidationException('The remediation action needs attention.', $errors);
        }
        return $v;
    }
}
