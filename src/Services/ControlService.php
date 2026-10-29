<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EvidenceStorage;
use App\Domain\AssessmentStatus as St;
use App\Domain\ControlCategory;
use App\Domain\ControlStatus;
use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Engine\ClearanceEvaluator;
use App\Infrastructure\AuditLogger;
use App\Infrastructure\PdoConnection;
use App\Repositories\ConfigRepository;
use App\Repositories\ControlRepository;
use App\Support\Clock;
use App\Support\ConflictException;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/** Stages 4–5: recommended controls, control assessment and evidence (PRD 9.1–9.2). */
final class ControlService
{
    public function __construct(
        private readonly PdoConnection $db,
        private readonly AssessmentService $assessments,
        private readonly ControlRepository $controls,
        private readonly ConfigRepository $configs,
        private readonly RecommendationService $recommendations,
        private readonly ResidualService $residual,
        private readonly ClearanceEvaluator $clearance,
        private readonly StatusTransitioner $status,
        private readonly EvidenceStorage $storage,
        private readonly Authorizer $authz,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * View model for the control screens: every recommended or assessed control with its assessment,
     * unmet clearance criteria and the reason it was recommended.
     *
     * @return list<array<string,mixed>>
     */
    public function overview(array $entity): array
    {
        $recommended = $this->recommendations->recommended($entity);
        $assessed = [];
        foreach ($this->controls->assessmentsFor((int) $entity['id']) as $a) {
            $assessed[$a['control_id']] = $a;
        }
        $config = $this->configs->active();
        $inputs = [];
        foreach ($this->residual->clearanceInputs($entity, $recommended) as $in) {
            $inputs[$in->controlId] = $in;
        }

        $rows = [];
        $ids = array_unique(array_merge(array_keys($recommended), array_keys($assessed)));
        foreach ($ids as $cid) {
            $control = $recommended[$cid]['control'] ?? null;
            $a = $assessed[$cid] ?? null;
            if ($control === null) {
                $control = $this->controls->find($cid);
            }
            $isRecommended = isset($recommended[$cid]);
            $counts = isset($inputs[$cid]);
            $rows[] = [
                'control' => $control,
                'assessment' => $a,
                'recommended' => $isRecommended,
                'source' => $isRecommended ? $recommended[$cid]['source'] : ($a['source'] ?? 'manual'),
                'reason' => $isRecommended ? $recommended[$cid]['reason'] : ($a !== null && $a['source'] === 'manual' ? 'Added manually by an assessor.' : 'No longer recommended for the current risk factors; kept for the record.'),
                'counts_for_clearance' => $counts,
                'mandatory_for_clearance' => $counts && $inputs[$cid]->mandatory,
                'unmet' => $counts ? $this->clearance->unmetCriteria($inputs[$cid], $config, $this->clock->today()) : [],
            ];
        }
        usort($rows, function ($a, $b) {
            $order = ['policy' => 1, 'process' => 2, 'technology' => 3];
            return [$order[$a['control']['category']], !$a['recommended'], $a['control']['id']]
                <=> [$order[$b['control']['category']], !$b['recommended'], $b['control']['id']];
        });
        return $rows;
    }

    private function assertCanEdit(PortalUser $user, array $e): void
    {
        if (!$this->authz->can($user, P::EditControls, $e)) {
            throw new ForbiddenException('Only control owners (Business Owner, Information Security, Privacy Team, Reviewer) can record controls.');
        }
    }

    /** Accept the recommendations: create a control_assessments row for each recommended control. */
    public function startControls(PortalUser $user, int $id): int
    {
        $e = $this->assessments->get($id);
        if (!$this->authz->can($user, P::EditOwn, $e) && !$this->authz->can($user, P::EditControls, $e)) {
            throw new ForbiddenException();
        }
        StatusTransitioner::assertEditable($e);
        if ($e['inherent_rating'] === null) {
            throw new ConflictException('Score the inherent risk first.');
        }
        return $this->db->transaction(function () use ($user, $id, $e) {
            $created = $this->ensureRows($user->id, $e);
            if ($e['status'] === St::Scored->value) {
                $this->status->to($user->id, $e, St::ControlsInProgress);
            }
            return $created;
        });
    }

    /** Create assessment rows for recommended controls that do not have one yet. Returns how many were created. */
    public function ensureRows(string $actor, array $e): int
    {
        $existing = [];
        foreach ($this->controls->assessmentsFor((int) $e['id']) as $a) {
            $existing[$a['control_id']] = true;
        }
        $created = [];
        foreach ($this->recommendations->recommended($e) as $cid => $rec) {
            if (!isset($existing[$cid])) {
                $this->db->insert('control_assessments', ['risk_entity_id' => (int) $e['id'], 'control_id' => $cid, 'source' => $rec['source']]);
                $created[] = $cid;
            }
        }
        if ($created !== []) {
            $this->audit->record($actor, 'control_assessments', (int) $e['id'], 'controls_recommended', null, ['risk_entity_id' => (int) $e['id'], 'control_ids' => $created]);
        }
        return count($created);
    }

    /** Add a library control that was not recommended ("control selection"). */
    public function addManualControl(PortalUser $user, int $id, int $controlId): void
    {
        $e = $this->assessments->get($id);
        $this->assertCanEdit($user, $e);
        StatusTransitioner::assertEditable($e);
        $c = $this->controls->find($controlId);
        if ($c === null || !$c['active']) {
            throw new NotFoundException('Control not found.');
        }
        if ($this->controls->assessment($id, $controlId) !== null) {
            return;
        }
        $this->db->transaction(function () use ($user, $id, $e, $controlId) {
            $this->db->insert('control_assessments', ['risk_entity_id' => $id, 'control_id' => $controlId, 'source' => 'manual']);
            $this->audit->record($user->id, 'control_assessments', $id, 'control_added', null, ['risk_entity_id' => $id, 'control_id' => $controlId, 'source' => 'manual']);
            if ($e['status'] === St::Scored->value) {
                $this->status->to($user->id, $e, St::ControlsInProgress);
            }
            $this->residual->persist($user->id, $id, $this->configs->active());
        });
    }

    /**
     * Record a control assessment (PRD 14 PUT /controls/{controlId}), optionally with an evidence file.
     *
     * @param array{tmp_name:string,name:string,error:int}|null $upload
     */
    public function record(PortalUser $user, int $id, int $controlId, array $in, ?array $upload = null): array
    {
        $e = $this->assessments->get($id);
        $this->assertCanEdit($user, $e);
        StatusTransitioner::assertEditable($e);
        if ($e['inherent_rating'] === null) {
            throw new ConflictException('Score the inherent risk first.');
        }
        $control = $this->controls->find($controlId);
        if ($control === null) {
            throw new NotFoundException('Control not found.');
        }
        $existing = $this->controls->assessment($id, $controlId);
        $recommended = $this->recommendations->recommended($e);
        if ($existing === null && !isset($recommended[$controlId])) {
            throw new ConflictException('This control is not part of the assessment. Add it first.');
        }

        $values = $this->validate($in, $existing);

        return $this->db->transaction(function () use ($user, $id, $e, $controlId, $values, $existing, $upload, $recommended) {
            if ($existing === null) {
                $this->db->insert('control_assessments', ['risk_entity_id' => $id, 'control_id' => $controlId, 'source' => $recommended[$controlId]['source']]);
            }
            if ($upload !== null) {
                $stored = $this->storage->store($upload['tmp_name'], $upload['name'], $id);
                $values += [
                    'evidence_path' => $stored->key,
                    'evidence_original_name' => $stored->originalName,
                    'evidence_mime' => $stored->mime,
                    // new evidence must be verified again
                    'verified_by' => null,
                    'verified_at' => null,
                ];
                if (($values['evidence_date'] ?? null) === null && ($existing['evidence_date'] ?? null) === null) {
                    $values['evidence_date'] = $this->clock->today()->format('Y-m-d');
                }
            }
            $this->controls->updateAssessment($id, $controlId, $values);
            $old = $existing === null ? null : array_intersect_key($existing, $values);
            $this->audit->record($user->id, 'control_assessments', $id, $upload !== null ? 'control_assessed_with_evidence' : 'control_assessed', $old, ['risk_entity_id' => $id, 'control_id' => $controlId] + $values);

            if ($e['status'] === St::Scored->value) {
                $this->status->to($user->id, $e, St::ControlsInProgress);
            }
            $this->residual->persist($user->id, $id, $this->configs->active());
            $this->closeFixedActions($user->id, $id);
            return $this->controls->assessment($id, $controlId);
        });
    }

    /** Upload evidence only (PRD 14 POST /evidence). */
    public function uploadEvidence(PortalUser $user, int $id, int $controlId, array $upload, array $in = []): array
    {
        return $this->record($user, $id, $controlId, $in, $upload);
    }

    private function validate(array $in, ?array $existing): array
    {
        $errors = [];
        $values = [];
        if (array_key_exists('status', $in)) {
            $st = ControlStatus::tryFrom((string) $in['status']);
            if ($st === null) {
                $errors['status'] = 'Choose a valid status.';
            } else {
                $values['status'] = $st->value;
            }
        }
        if (array_key_exists('effectiveness_pct', $in)) {
            $v = trim((string) $in['effectiveness_pct']);
            if ($v === '') {
                $values['effectiveness_pct'] = null;
            } elseif (!ctype_digit($v) || (int) $v > 100) {
                $errors['effectiveness_pct'] = 'Effectiveness must be a whole number from 0 to 100.';
            } else {
                $values['effectiveness_pct'] = (int) $v;
            }
        }
        foreach (['evidence_date', 'expiry_date'] as $d) {
            if (array_key_exists($d, $in)) {
                $v = trim((string) $in[$d]);
                if ($v === '') {
                    $values[$d] = null;
                } elseif (!AssessmentService::isDate($v)) {
                    $errors[$d] = 'Use the format YYYY-MM-DD.';
                } else {
                    $values[$d] = $v;
                }
            }
        }
        $evDate = $values['evidence_date'] ?? ($existing['evidence_date'] ?? null);
        $exDate = $values['expiry_date'] ?? ($existing['expiry_date'] ?? null);
        if (!isset($errors['expiry_date']) && $evDate !== null && $exDate !== null && substr((string) $exDate, 0, 10) < substr((string) $evDate, 0, 10)) {
            $errors['expiry_date'] = 'Evidence cannot expire before it was produced.';
        }
        if (array_key_exists('evidence_owner', $in)) {
            $values['evidence_owner'] = mb_substr(trim((string) $in['evidence_owner']), 0, 150) ?: null;
        }
        if (array_key_exists('notes', $in)) {
            $values['notes'] = trim((string) $in['notes']) ?: null;
        }
        if ($errors !== []) {
            throw new ValidationException('Some control details need attention.', $errors);
        }
        return $values;
    }

    /** A Reviewer verifies the evidence (PRD 9.2 "verified by a Reviewer"). */
    public function verify(PortalUser $user, int $id, int $controlId, bool $verified = true): void
    {
        $e = $this->assessments->get($id);
        $this->authz->authorize($user, P::VerifyEvidence, $e);
        StatusTransitioner::assertEditable($e);
        $a = $this->controls->assessment($id, $controlId);
        if ($a === null || empty($a['evidence_path'])) {
            throw new ConflictException('There is no evidence to verify yet.');
        }
        $this->db->transaction(function () use ($user, $id, $controlId, $a, $verified) {
            $values = $verified
                ? ['verified_by' => $user->id, 'verified_at' => $this->clock->now()->format('Y-m-d H:i:s')]
                : ['verified_by' => null, 'verified_at' => null];
            $this->controls->updateAssessment($id, $controlId, $values);
            $this->audit->record($user->id, 'control_assessments', $id, $verified ? 'evidence_verified' : 'evidence_unverified', ['verified_by' => $a['verified_by'], 'verified_at' => $a['verified_at']], ['risk_entity_id' => $id, 'control_id' => $controlId] + $values);
            $this->residual->persist($user->id, $id, $this->configs->active());
            $this->closeFixedActions($user->id, $id);
        });
    }

    /** @return array{path:string,name:string,mime:string} */
    public function evidenceFile(PortalUser $user, int $id, int $controlId): array
    {
        $e = $this->assessments->getForUser($user, $id);
        $a = $this->controls->assessment((int) $e['id'], $controlId);
        if ($a === null || empty($a['evidence_path'])) {
            throw new NotFoundException('No evidence file.');
        }
        $path = $this->storage->open($a['evidence_path']);
        if ($path === null) {
            throw new NotFoundException('The evidence file is missing from storage.');
        }
        return ['path' => $path, 'name' => $a['evidence_original_name'] ?: 'evidence', 'mime' => $a['evidence_mime'] ?: 'application/octet-stream'];
    }

    /**
     * Close open remediation actions whose control now meets every clearance criterion, so nobody has
     * to tick them off by hand. Recorded in the audit log as done by "system", noting who triggered it.
     * Must run inside the transaction that changed the control.
     *
     * @return int number of actions closed
     */
    public function closeFixedActions(string $triggeredBy, int $entityId): int
    {
        $met = [];
        foreach ($this->overview($this->assessments->get($entityId)) as $row) {
            if ($row['counts_for_clearance'] && $row['unmet'] === []) {
                $met[(int) $row['control']['id']] = true;
            }
        }
        $closed = 0;
        $open = $this->db->fetchAll(
            "SELECT id, control_id, status FROM remediation_actions
              WHERE risk_entity_id = :e AND control_id IS NOT NULL AND status IN ('open', 'in_progress')",
            ['e' => $entityId],
        );
        foreach ($open as $a) {
            if (!isset($met[(int) $a['control_id']])) {
                continue;
            }
            $new = ['status' => 'completed', 'completion_pct' => 100, 'completed_at' => $this->clock->now()->format('Y-m-d H:i:s')];
            $this->db->update('remediation_actions', $new, 'id = :id', ['id' => (int) $a['id']]);
            $this->audit->record('system', 'remediation_actions', (int) $a['id'], 'auto_completed', ['status' => $a['status']], [
                'risk_entity_id' => $entityId, 'control_id' => (int) $a['control_id'], 'triggered_by' => $triggeredBy,
                'reason' => 'The control now meets all clearance criteria.',
            ] + $new);
            $closed++;
        }
        return $closed;
    }

    /** Gaps (unmet recommended controls) that do not yet have an open remediation action. */
    public function gaps(array $entity): array
    {
        $out = [];
        foreach ($this->overview($entity) as $row) {
            if ($row['counts_for_clearance'] && $row['unmet'] !== []) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public static function categoryOrder(): array
    {
        return array_map(fn (ControlCategory $c) => $c->value, ControlCategory::cases());
    }
}
