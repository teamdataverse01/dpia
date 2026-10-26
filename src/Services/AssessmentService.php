<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AssessmentStatus as St;
use App\Domain\EntityType;
use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\Rating;
use App\Engine\InherentRiskCalculator;
use App\Engine\InherentRiskResult;
use App\Engine\ResidualRiskResult;
use App\Infrastructure\AuditLogger;
use App\Infrastructure\PdoConnection;
use App\Repositories\ConfigRepository;
use App\Repositories\ControlRepository;
use App\Repositories\ReferenceRepository;
use App\Repositories\RiskEntityRepository;
use App\Support\Clock;
use App\Support\ConflictException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/** Stages 1–3 and 6 of the shared workflow, plus reassessment (PRD 6–9). */
final class AssessmentService
{
    public function __construct(
        private readonly PdoConnection $db,
        private readonly RiskEntityRepository $entities,
        private readonly ReferenceRepository $refs,
        private readonly ConfigRepository $configs,
        private readonly ControlRepository $controls,
        private readonly FactorCatalog $catalog,
        private readonly InherentRiskCalculator $calculator,
        private readonly ResidualService $residual,
        private readonly StatusTransitioner $status,
        private readonly Authorizer $authz,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    // --- lookups ------------------------------------------------------------------

    /** @throws NotFoundException */
    public function get(int $id): array
    {
        $e = $this->entities->find($id);
        if ($e === null) {
            throw new NotFoundException('Assessment not found.');
        }
        return $e;
    }

    /** Load and check the user may view it. Hidden records look "not found" to avoid leaking existence. */
    public function getForUser(PortalUser $user, int $id): array
    {
        $e = $this->get($id);
        if (!$this->authz->can($user, P::View, $e)) {
            throw new NotFoundException('Assessment not found.');
        }
        return $e;
    }

    // --- stage 1: subject setup -----------------------------------------------------

    public function create(PortalUser $user, EntityType $type, array $in): int
    {
        $this->authz->authorize($user, P::Create);
        [$common, $profile] = $this->validateSubject($type, $in);
        $config = $this->configs->active();

        return $this->db->transaction(function () use ($user, $type, $common, $profile, $config) {
            $id = $this->entities->create($common + [
                'entity_type' => $type->value,
                'owner_portal_user_id' => $user->id,
                'status' => St::Draft->value,
                'scoring_framework_version' => $config->version,
            ]);
            if ($type === EntityType::Vendor) {
                $this->entities->createVendorProfile($id, $profile);
            } else {
                $this->entities->createProcessingActivity($id, $profile);
            }
            $this->audit->record($user->id, 'risk_entities', $id, 'create', null, $common + $profile + ['entity_type' => $type->value]);
            return $id;
        });
    }

    public function updateSubject(PortalUser $user, int $id, array $in): void
    {
        $e = $this->get($id);
        $this->authz->authorize($user, P::EditOwn, $e);
        StatusTransitioner::assertEditable($e);
        $type = EntityType::from($e['entity_type']);
        [$common, $profile] = $this->validateSubject($type, $in);

        $this->db->transaction(function () use ($user, $id, $e, $type, $common, $profile) {
            $this->entities->update($id, $common);
            if ($type === EntityType::Vendor) {
                $this->entities->updateVendorProfile($id, $profile);
            } else {
                $this->entities->updateProcessingActivity($id, $profile);
            }
            $old = array_intersect_key($e, $common + $profile);
            $this->audit->record($user->id, 'risk_entities', $id, 'update_subject', $old, $common + $profile);
        });
    }

    /** @return array{0: array, 1: array} [risk_entities columns, profile columns] */
    private function validateSubject(EntityType $type, array $in): array
    {
        $errors = [];
        $s = fn (string $k, int $max = 0) => $max > 0 ? mb_substr(trim((string) ($in[$k] ?? '')), 0, $max) : trim((string) ($in[$k] ?? ''));
        $nullable = fn (string $v) => $v === '' ? null : $v;

        $name = $s('name', 200);
        if ($name === '') {
            $errors['name'] = $type === EntityType::Vendor ? 'Enter the vendor name.' : 'Enter the name of the processing activity.';
        }
        $dept = (int) ($in['department_id'] ?? 0);
        if ($dept <= 0 || $this->refs->department($dept) === null) {
            $errors['department_id'] = 'Choose the department responsible.';
        }

        if ($type === EntityType::Vendor) {
            $profile = [
                'legal_name' => $s('legal_name', 200) ?: $name,
                'service_description' => $s('service_description'),
                'contract_start' => $nullable($s('contract_start')),
                'contract_end' => $nullable($s('contract_end')),
                'subprocessors' => $nullable($s('subprocessors')),
                'contact_name' => $nullable($s('contact_name', 150)),
                'contact_email' => $nullable($s('contact_email', 200)),
                'vendor_portal_user_id' => $nullable($s('vendor_portal_user_id', 100)),
            ];
            if ($profile['service_description'] === '') {
                $errors['service_description'] = 'Describe the service the vendor provides to CU.';
            }
            foreach (['contract_start', 'contract_end'] as $d) {
                if ($profile[$d] !== null && !self::isDate($profile[$d])) {
                    $errors[$d] = 'Use the format YYYY-MM-DD.';
                }
            }
            if ($profile['contract_start'] !== null && $profile['contract_end'] !== null
                && self::isDate($profile['contract_start']) && self::isDate($profile['contract_end'])
                && $profile['contract_end'] < $profile['contract_start']) {
                $errors['contract_end'] = 'The contract cannot end before it starts.';
            }
            if ($profile['contact_email'] !== null && filter_var($profile['contact_email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['contact_email'] = 'Enter a valid e-mail address.';
            }
        } else {
            $profile = [
                'purpose' => $s('purpose'),
                'description' => $s('description'),
                'retention_period' => $nullable($s('retention_period', 100)),
            ];
            if ($profile['purpose'] === '') {
                $errors['purpose'] = 'Explain why CU needs to process this data.';
            }
            if ($profile['description'] === '') {
                $errors['description'] = 'Describe what happens to the data, step by step.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Some details need attention.', $errors);
        }
        return [['name' => $name, 'department_id' => $dept], $profile];
    }

    public static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    // --- stage 2–3: factors and inherent risk -------------------------------------------

    /** Save the shared factor panel, validate 7.1, and recalculate (PRD 14 PUT /factors). */
    public function saveFactors(PortalUser $user, int $id, array $in): InherentRiskResult
    {
        $e = $this->get($id);
        $this->authz->authorize($user, P::EditOwn, $e);
        StatusTransitioner::assertEditable($e);
        $type = EntityType::from($e['entity_type']);
        $factors = $this->catalog->normalise($type, $in, $id);

        return $this->db->transaction(function () use ($user, $id, $e, $factors) {
            $old = $this->entities->factorInputs($id);
            $this->entities->saveFactorInputs($id, $factors);
            $this->audit->record($user->id, 'risk_factor_inputs', $id, $old === null ? 'create' : 'update', $old === null ? null : self::comparable($old), ['risk_entity_id' => $id] + $factors);

            if ($e['template_id'] !== null && !$e['customised'] && $this->differsFromTemplate((int) $e['template_id'], $factors)) {
                $this->entities->update($id, ['customised' => true]);
                $this->audit->record($user->id, 'risk_entities', $id, 'customised', ['customised' => false], ['customised' => true]);
            }

            $result = $this->scoreInTransaction($user->id, $id);

            // Changing factors after the residual was confirmed means it must be re-confirmed.
            $fresh = $this->get($id);
            if ($fresh['status'] === St::ResidualCalculated->value) {
                $next = $this->controls->hasAssessments($id) ? St::ControlsInProgress : St::Scored;
                $this->status->to($user->id, $fresh, $next);
            }
            return $result;
        });
    }

    private function differsFromTemplate(int $templateId, array $factors): bool
    {
        $row = $this->db->fetchOne('SELECT factor_snapshot, involves_data_sharing, default_vendor_entity_id FROM activity_templates WHERE id = :id', ['id' => $templateId]);
        if ($row === null) {
            return false;
        }
        $snap = json_decode($row['factor_snapshot'], true) ?: [];
        $snap['involves_data_sharing'] = (bool) $row['involves_data_sharing'];
        $snap['vendor_risk_ref'] = $row['default_vendor_entity_id'] === null ? null : (int) $row['default_vendor_entity_id'];
        return self::comparable($snap) != self::comparable($factors);
    }

    private static function comparable(array $f): array
    {
        $types = $f['data_type'] ?? [];
        sort($types);
        $out = ['data_type' => $types];
        foreach (FactorCatalog::OPTION_FIELDS as $k) {
            $out[$k] = $f[$k] ?? null;
        }
        $out['involves_data_sharing'] = (bool) ($f['involves_data_sharing'] ?? false);
        $out['vendor_risk_ref'] = isset($f['vendor_risk_ref']) ? (int) $f['vendor_risk_ref'] : null;
        return $out;
    }

    /** Live score preview without saving (used by the wizard's JS and the template "Customise" screen). */
    public function preview(PortalUser $user, EntityType $type, array $in, ?int $selfId = null): InherentRiskResult
    {
        if ($selfId !== null) {
            $this->getForUser($user, $selfId);
        }
        $factors = $this->catalog->normalise($type, $in, $selfId);
        return $this->calculator->calculate(
            $type,
            $factors['involves_data_sharing'],
            $this->catalog->selections($type, $factors),
            $this->configs->active(),
        );
    }

    /** Recalculate inherent and residual (PRD 14 POST /score). */
    public function score(PortalUser $user, int $id): array
    {
        $e = $this->get($id);
        if (!$this->authz->can($user, P::EditOwn, $e) && !$this->authz->can($user, P::EditControls, $e)) {
            throw new \App\Support\ForbiddenException();
        }
        StatusTransitioner::assertEditable($e);
        return $this->db->transaction(function () use ($user, $id) {
            $inherent = $this->scoreInTransaction($user->id, $id);
            $residual = $this->residual->persist($user->id, $id, $this->configs->active());
            return ['inherent' => $inherent, 'residual' => $residual];
        });
    }

    /** Must run inside a transaction. Writes breakdown rows, entity scores, audit, then refreshes residual. */
    public function scoreInTransaction(string $actor, int $id): InherentRiskResult
    {
        $e = $this->get($id);
        $factors = $this->entities->factorInputs($id);
        if ($factors === null) {
            throw new ConflictException('Enter the risk factors before scoring.');
        }
        $type = EntityType::from($e['entity_type']);
        $config = $this->configs->active();
        $result = $this->calculator->calculate($type, (bool) $factors['involves_data_sharing'], $this->catalog->selections($type, $factors), $config);

        $this->entities->insertBreakdown($id, $result, $this->clock->now()->format('Y-m-d H:i:s.u'));
        $old = ['inherent_score' => $e['inherent_score'], 'inherent_rating' => $e['inherent_rating'], 'framework_version' => (int) $e['scoring_framework_version']];
        $new = ['inherent_score' => number_format($result->score, 2, '.', ''), 'inherent_rating' => $result->rating->value, 'framework_version' => $config->version];
        $this->entities->update($id, [
            'inherent_score' => $new['inherent_score'],
            'inherent_rating' => $new['inherent_rating'],
            'scoring_framework_version' => $config->version,
        ]);
        $this->audit->record($actor, 'risk_entities', $id, 'inherent_scored', $old, $new + [
            'breakdown' => array_map(fn ($c) => [$c->factor->value, $c->selectedOption, $c->score, $c->weightUsed], $result->breakdown),
        ]);

        if (in_array($e['status'], [St::Draft->value, St::Rejected->value, St::ReassessmentDue->value], true)) {
            $this->status->to($actor, $this->get($id), St::Scored);
        }
        $this->residual->persist($actor, $id, $config);
        return $result;
    }

    /** The last stored calculation, rebuilt as an explanation payload (no recalculation, no writes). */
    public function storedInherent(int $id): ?array
    {
        $e = $this->get($id);
        if ($e['inherent_score'] === null) {
            return null;
        }
        $rows = $this->entities->latestBreakdown($id);
        $breakdown = [];
        foreach ($rows as $r) {
            $label = str_starts_with($r['selected_option'], 'vendor:')
                ? $this->vendorLabel((int) substr($r['selected_option'], 7))
                : $this->catalog->label($r['factor'], $r['selected_option']);
            $breakdown[] = [
                'factor' => $r['factor'],
                'factor_label' => \App\Domain\Factor::from($r['factor'])->label(),
                'selected_option' => $r['selected_option'],
                'option_label' => $label,
                'score' => (int) $r['factor_score'],
                'weight_used' => (float) $r['weight_used'],
                'weighted_value' => (float) $r['weighted_value'],
                'calculated_at' => $r['calculated_at'],
                'framework_version' => $r['framework_version'] === null ? null : (int) $r['framework_version'],
            ];
        }
        $factors = $this->entities->factorInputs($id);
        $type = EntityType::from($e['entity_type']);
        $plan = $this->calculator->activeFactors($type, (bool) ($factors['involves_data_sharing'] ?? false));
        return [
            'inherent_score' => (float) $e['inherent_score'],
            'inherent_rating' => $e['inherent_rating'],
            'inherent_rating_label' => Rating::from($e['inherent_rating'])->label(),
            'framework_version' => (int) $e['scoring_framework_version'],
            'breakdown' => $breakdown,
            'excluded_factors' => array_map(fn ($f) => ['factor' => $f->value, 'factor_label' => $f->label(), 'reason' => $plan['reasons'][$f->value]], $plan['excluded']),
        ];
    }

    private function vendorLabel(int $vendorId): string
    {
        $v = $this->entities->find($vendorId);
        return $v === null ? 'Vendor #' . $vendorId : $v['name'];
    }

    // --- stage 6: residual -------------------------------------------------------------

    /** Explicitly calculate the residual and move to "residual_calculated". */
    public function calculateResidual(PortalUser $user, int $id): ResidualRiskResult
    {
        $e = $this->get($id);
        if (!$this->authz->can($user, P::EditOwn, $e) && !$this->authz->can($user, P::EditControls, $e)) {
            throw new \App\Support\ForbiddenException();
        }
        StatusTransitioner::assertEditable($e);
        if ($e['inherent_rating'] === null) {
            throw new ConflictException('Score the inherent risk first.');
        }
        return $this->db->transaction(function () use ($user, $id) {
            $r = $this->residual->persist($user->id, $id, $this->configs->active());
            $e = $this->get($id);
            if (in_array($e['status'], [St::Scored->value, St::ControlsInProgress->value, St::Rejected->value, St::ReassessmentDue->value], true)) {
                if (in_array($e['status'], [St::Rejected->value, St::ReassessmentDue->value], true)) {
                    $this->status->to($user->id, $e, St::Scored);
                    $e = $this->get($id);
                }
                $this->status->to($user->id, $e, St::ResidualCalculated);
            }
            return $r;
        });
    }

    /** Explanation payload for GET /residual (no writes). */
    public function residualExplanation(array $e): ?array
    {
        if ($e['inherent_rating'] === null) {
            return null;
        }
        $config = $this->configs->active();
        [$clear, $r] = $this->residual->evaluate($e, $config);
        return $r->toArray() + [
            'categories' => $clear->toArray(),
            'control_gaps' => $clear->controlGaps,
            'inherent_score' => (float) $e['inherent_score'],
            'inherent_rating' => $e['inherent_rating'],
            'reduction_weights' => $config->reductionWeights,
        ];
    }

    /** PRD 9.3 — Very Low confirmation by a Reviewer or Risk Approver, with justification. */
    public function confirmNegligible(PortalUser $user, int $id, string $justification, bool $confirm = true): ResidualRiskResult
    {
        $e = $this->get($id);
        $this->authz->authorize($user, P::ConfirmNegligible, $e);
        $justification = trim($justification);
        if ($confirm) {
            if (mb_strlen($justification) < 10) {
                throw new ValidationException('Explain why the remaining risk is negligible.', ['justification' => 'Write at least a sentence explaining why the remaining risk is negligible.']);
            }
            [, $r] = $this->residual->evaluate(['negligible_residual_confirmed' => false] + $e, $this->configs->active());
            if ($r->computedRating !== Rating::Low) {
                throw new ConflictException('Very Low can only be confirmed when the computed residual rating is Low (it is currently ' . $r->computedRating->label() . ').');
            }
        }
        if (in_array($e['status'], [St::PendingApproval->value], true)) {
            throw new ConflictException('This assessment is waiting for approval; confirm negligible risk before submitting.');
        }

        return $this->db->transaction(function () use ($user, $id, $e, $justification, $confirm) {
            $new = ['negligible_residual_confirmed' => $confirm, 'negligible_justification' => $confirm ? $justification : null];
            $this->entities->update($id, $new);
            $this->audit->record(
                $user->id,
                'risk_entities',
                $id,
                $confirm ? 'negligible_confirmed' : 'negligible_withdrawn',
                ['negligible_residual_confirmed' => (bool) $e['negligible_residual_confirmed'], 'negligible_justification' => $e['negligible_justification']],
                $new,
            );
            return $this->residual->persist($user->id, $id, $this->configs->active());
        });
    }

    // --- stage 9: monitoring ---------------------------------------------------------

    /** Re-open a decided assessment for reassessment (keeps factors, controls and history). */
    public function startReassessment(PortalUser $user, int $id): void
    {
        $e = $this->get($id);
        if (!$this->authz->can($user, P::EditOwn, $e) && !$this->authz->can($user, P::EditControls, $e)) {
            throw new \App\Support\ForbiddenException();
        }
        $s = St::from($e['status']);
        if (!$s->isDecided()) {
            throw new ConflictException('Only approved, risk-accepted or monitored assessments can be reassessed.');
        }
        $this->db->transaction(function () use ($user, $id, $e, $s) {
            if ($s !== St::ReassessmentDue) {
                $this->status->to($user->id, $e, St::ReassessmentDue);
                $e = $this->get($id);
            }
            if ($e['negligible_residual_confirmed']) {
                $this->entities->update($id, ['negligible_residual_confirmed' => false, 'negligible_justification' => null]);
                $this->audit->record($user->id, 'risk_entities', $id, 'negligible_withdrawn', ['negligible_residual_confirmed' => true], ['negligible_residual_confirmed' => false, 'reason' => 'reassessment']);
            }
            $this->scoreInTransaction($user->id, $id); // reassessment_due → scored, with the current framework
        });
    }
}
