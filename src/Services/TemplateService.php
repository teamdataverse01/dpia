<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AssessmentStatus as St;
use App\Domain\EntityType;
use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Engine\InherentRiskCalculator;
use App\Engine\InherentRiskResult;
use App\Engine\TemplateMatch;
use App\Engine\TemplateMatcher;
use App\Infrastructure\AuditLogger;
use App\Infrastructure\PdoConnection;
use App\Repositories\ConfigRepository;
use App\Repositories\ControlRepository;
use App\Repositories\RiskEntityRepository;
use App\Repositories\TemplateRepository;
use App\Support\Clock;
use App\Support\ConflictException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/** PRD 10 — the DPIA fast path. */
final class TemplateService
{
    public function __construct(
        private readonly PdoConnection $db,
        private readonly TemplateRepository $templates,
        private readonly ControlRepository $controls,
        private readonly RiskEntityRepository $entities,
        private readonly ConfigRepository $configs,
        private readonly AssessmentService $assessments,
        private readonly ControlService $controlService,
        private readonly FactorCatalog $catalog,
        private readonly InherentRiskCalculator $calculator,
        private readonly TemplateMatcher $matcher,
        private readonly StatusTransitioner $status,
        private readonly Authorizer $authz,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    public function get(int $id): array
    {
        $t = $this->templates->find($id);
        if ($t === null) {
            throw new NotFoundException('Template not found.');
        }
        return $t;
    }

    /** Search (department + name) with staleness flags. */
    public function search(?int $departmentId, ?string $q): array
    {
        $config = $this->configs->active();
        return array_map(function (array $t) use ($config) {
            [$stale, $why] = $this->matcher->staleness(TemplateRepository::candidate($t), $config, $this->clock->today());
            return $t + ['stale' => $stale, 'stale_reasons' => $why];
        }, $this->templates->search($departmentId, $q));
    }

    /**
     * Deterministic matching (exact name+department first, then signatures).
     *
     * @return list<TemplateMatch>
     */
    public function match(?int $departmentId, ?string $activityName, ?array $factors): array
    {
        $dt = $ds = $ch = null;
        if ($factors !== null) {
            $dt = isset($factors['data_type']) ? TemplateMatcher::dataTypeSignature((array) $factors['data_type']) : null;
            $ds = isset($factors['data_subject_type']) ? TemplateMatcher::dataSubjectSignature((string) $factors['data_subject_type']) : null;
            if (isset($factors['nature_of_processing'], $factors['automated_decision_making'], $factors['cross_border_transfer'], $factors['third_party_transfer'])) {
                $ch = TemplateMatcher::characteristicsSignature(
                    (string) $factors['nature_of_processing'],
                    (string) $factors['automated_decision_making'],
                    (string) $factors['cross_border_transfer'],
                    (string) $factors['third_party_transfer'],
                );
            }
        }
        return $this->matcher->match(
            $this->templates->candidates(),
            $departmentId,
            $activityName,
            $dt,
            $ds,
            $ch,
            $this->configs->active(),
            $this->clock->today(),
        );
    }

    /** The template's factor values, with its default vendor (read live at scoring time). */
    public function factors(array $t): array
    {
        $snap = json_decode((string) $t['factor_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $snap['involves_data_sharing'] = (bool) $t['involves_data_sharing'];
        $snap['vendor_risk_ref'] = $t['default_vendor_entity_id'] === null ? null : (int) $t['default_vendor_entity_id'];
        return $snap;
    }

    /**
     * Everything the fast-path screen shows immediately: stored score, live score, controls, staleness.
     */
    public function preview(int $id): array
    {
        $t = $this->get($id);
        $config = $this->configs->active();
        [$stale, $why] = $this->matcher->staleness(TemplateRepository::candidate($t), $config, $this->clock->today());
        $factors = $this->factors($t);
        $live = null;
        $liveError = null;
        try {
            $live = $this->liveScore($factors);
        } catch (ValidationException $e) {
            $liveError = $e->getMessage();
        }
        $controls = [];
        foreach ($this->controls->templateControlIds($id) as $cid) {
            $c = $this->controls->find($cid);
            if ($c !== null) {
                $controls[] = $c;
            }
        }
        return [
            'template' => $t,
            'factors' => $factors,
            'stale' => $stale,
            'stale_reasons' => $why,
            'live' => $live,
            'live_error' => $liveError,
            'controls' => $controls,
        ];
    }

    private function liveScore(array $factors): InherentRiskResult
    {
        return $this->calculator->calculate(
            EntityType::ProcessingActivity,
            (bool) $factors['involves_data_sharing'],
            $this->catalog->selections(EntityType::ProcessingActivity, $factors),
            $this->configs->active(),
        );
    }

    /**
     * Fast path from the web: create the DPIA and apply the template in one step.
     *
     * @param 'accept'|'customise' $mode
     */
    public function startFromTemplate(PortalUser $user, int $templateId, string $mode, array $subject, bool $confirmStale): int
    {
        $t = $this->get($templateId);
        $subject['department_id'] = $subject['department_id'] ?? $t['department_id'];
        if (trim((string) ($subject['name'] ?? '')) === '') {
            $subject['name'] = $t['activity_name'];
        }
        return $this->db->transaction(function () use ($user, $templateId, $mode, $subject, $confirmStale) {
            $id = $this->assessments->create($user, EntityType::ProcessingActivity, $subject);
            $this->apply($user, $id, $templateId, $mode, $confirmStale);
            return $id;
        });
    }

    /**
     * PRD 14 POST /risk-entities/{id}/apply-template.
     *
     * Accept: factors prefilled, scored, standard controls prefilled → straight to control assessment.
     * Customise: factors prefilled and editable; any edit marks the assessment customised.
     *
     * @param 'accept'|'customise' $mode
     */
    public function apply(PortalUser $user, int $entityId, int $templateId, string $mode, bool $confirmStale): array
    {
        if (!in_array($mode, ['accept', 'customise'], true)) {
            throw new ValidationException('Choose Accept or Customise.', ['mode' => 'Choose Accept or Customise.']);
        }
        $e = $this->assessments->get($entityId);
        $this->authz->authorize($user, P::EditOwn, $e);
        if ($e['entity_type'] !== EntityType::ProcessingActivity->value) {
            throw new ConflictException('Templates are only for DPIAs.');
        }
        if (!in_array($e['status'], [St::Draft->value, St::Scored->value], true)) {
            throw new ConflictException('A template can only be applied to a new DPIA.');
        }
        $t = $this->get($templateId);
        [$stale, $why] = $this->matcher->staleness(TemplateRepository::candidate($t), $this->configs->active(), $this->clock->today());
        if ($stale && !$confirmStale) {
            throw new ValidationException(
                'This template may be out of date: ' . implode(' ', $why) . ' Confirm you have checked it still applies.',
                ['confirm_stale' => 'Tick the box to confirm the template still applies.'],
            );
        }
        $factors = $this->factors($t);

        return $this->db->transaction(function () use ($user, $entityId, $templateId, $mode, $factors, $stale) {
            $this->entities->update($entityId, ['template_id' => $templateId, 'customised' => false]);
            $this->audit->record($user->id, 'risk_entities', $entityId, 'template_applied', null, [
                'template_id' => $templateId, 'mode' => $mode, 'stale_confirmed' => $stale,
            ]);
            $result = $this->assessments->saveFactors($user, $entityId, $factors);
            if ($mode === 'accept') {
                $this->controlService->ensureRows($user->id, $this->assessments->get($entityId));
                $fresh = $this->assessments->get($entityId);
                if ($fresh['status'] === St::Scored->value) {
                    $this->status->to($user->id, $fresh, St::ControlsInProgress);
                }
            }
            return ['inherent' => $result, 'mode' => $mode];
        });
    }

    /** PRD 10.3 — after final approval, save a DPIA as a reusable template. */
    public function saveFromEntity(PortalUser $user, int $entityId, ?string $activityName = null): int
    {
        $this->authz->authorize($user, P::SaveTemplate);
        $e = $this->assessments->get($entityId);
        if ($e['entity_type'] !== EntityType::ProcessingActivity->value) {
            throw new ConflictException('Only DPIAs can be saved as templates.');
        }
        if (!St::from($e['status'])->isDecided()) {
            throw new ConflictException('A DPIA can be saved as a template only after final approval.');
        }
        if ($e['department_id'] === null) {
            throw new ConflictException('The DPIA needs a department before it can become a template.');
        }
        $factors = $this->entities->factorInputs($entityId);
        if ($factors === null || $e['inherent_score'] === null) {
            throw new ConflictException('The DPIA has no scored factors.');
        }
        $name = trim((string) ($activityName ?: $e['name']));
        if ($this->templates->existsByName((int) $e['department_id'], $name)) {
            throw new ValidationException('A template with this name already exists for the department.', ['activity_name' => 'Choose a different name.']);
        }
        $config = $this->configs->active();
        $controlIds = array_map(fn ($a) => (int) $a['control_id'], $this->controls->assessmentsFor($entityId));

        return $this->db->transaction(function () use ($user, $e, $entityId, $factors, $name, $config, $controlIds) {
            $snapshot = $factors;
            unset($snapshot['id'], $snapshot['risk_entity_id'], $snapshot['created_at'], $snapshot['updated_at'], $snapshot['involves_data_sharing'], $snapshot['vendor_risk_ref']);
            $values = [
                'department_id' => (int) $e['department_id'],
                'activity_name' => $name,
                'data_type_signature' => TemplateMatcher::dataTypeSignature($factors['data_type']),
                'data_subject_signature' => TemplateMatcher::dataSubjectSignature($factors['data_subject_type']),
                'characteristics_signature' => TemplateMatcher::characteristicsSignature($factors['nature_of_processing'], $factors['automated_decision_making'], $factors['cross_border_transfer'], $factors['third_party_transfer']),
                'involves_data_sharing' => (bool) $factors['involves_data_sharing'],
                'default_vendor_entity_id' => $factors['involves_data_sharing'] ? $factors['vendor_risk_ref'] : null,
                'inherent_score' => $e['inherent_score'],
                'inherent_rating' => $e['inherent_rating'],
                'factor_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'framework_version' => $config->version,
                'last_reviewed_at' => $this->clock->today()->format('Y-m-d'),
                'source_entity_id' => $entityId,
                'created_by' => $user->id,
            ];
            $id = $this->templates->create($values);
            $this->templates->setControls($id, $controlIds);
            $this->audit->record($user->id, 'activity_templates', $id, 'create', null, $values + ['control_ids' => $controlIds]);
            return $id;
        });
    }

    /**
     * Administrator bulk re-score (PRD 10.4): recompute every template with the current framework
     * and live vendor scores, and mark it reviewed today.
     *
     * @return list<array{id:int,name:string,old:string,new:string,error:?string}>
     */
    public function rescoreAll(PortalUser $user): array
    {
        $this->authz->authorize($user, P::AdminConfig);
        $config = $this->configs->active();
        return $this->db->transaction(function () use ($user, $config) {
            $out = [];
            foreach ($this->templates->search(null, null) as $t) {
                try {
                    $r = $this->liveScore($this->factors($t));
                } catch (ValidationException $e) {
                    $out[] = ['id' => (int) $t['id'], 'name' => $t['activity_name'], 'old' => (string) $t['inherent_score'], 'new' => '—', 'error' => $e->getMessage()];
                    continue;
                }
                $new = [
                    'inherent_score' => number_format($r->score, 2, '.', ''),
                    'inherent_rating' => $r->rating->value,
                    'framework_version' => $config->version,
                    'last_reviewed_at' => $this->clock->today()->format('Y-m-d'),
                ];
                $this->templates->update((int) $t['id'], $new);
                $this->audit->record($user->id, 'activity_templates', (int) $t['id'], 'rescored', [
                    'inherent_score' => $t['inherent_score'], 'inherent_rating' => $t['inherent_rating'],
                    'framework_version' => (int) $t['framework_version'], 'last_reviewed_at' => $t['last_reviewed_at'],
                ], $new);
                $out[] = ['id' => (int) $t['id'], 'name' => $t['activity_name'], 'old' => (string) $t['inherent_score'], 'new' => $new['inherent_score'], 'error' => null];
            }
            return $out;
        });
    }
}
