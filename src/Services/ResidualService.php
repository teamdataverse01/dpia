<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ControlCategory;
use App\Domain\ControlStatus;
use App\Domain\EntityType;
use App\Domain\Rating;
use App\Engine\ClearanceEvaluator;
use App\Engine\ClearanceResult;
use App\Engine\ControlAssessmentInput;
use App\Engine\ResidualRiskCalculator;
use App\Engine\ResidualRiskResult;
use App\Engine\ScoringConfig;
use App\Infrastructure\AuditLogger;
use App\Repositories\ControlRepository;
use App\Repositories\RiskEntityRepository;
use App\Support\Clock;

/** Stage 5→6 — clearance of control categories and the residual rating (PRD 9.2–9.4). */
final class ResidualService
{
    public function __construct(
        private readonly RiskEntityRepository $entities,
        private readonly ControlRepository $controls,
        private readonly RecommendationService $recommendations,
        private readonly ClearanceEvaluator $clearance,
        private readonly ResidualRiskCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The controls that count for clearance: currently recommended ones (with their mandatory flag)
     * plus manually added ones (never mandatory — they can help but never block; DECISIONS D-08).
     *
     * @return list<ControlAssessmentInput>
     */
    public function clearanceInputs(array $entity, ?array $recommended = null): array
    {
        $recommended ??= $this->recommendations->recommended($entity);
        $assessed = [];
        foreach ($this->controls->assessmentsFor((int) $entity['id']) as $a) {
            $assessed[$a['control_id']] = $a;
        }
        $inputs = [];
        foreach ($recommended as $cid => $rec) {
            $inputs[] = self::input($rec['control'] + ['control_id' => $cid], $assessed[$cid] ?? null, (bool) $rec['control']['mandatory']);
        }
        foreach ($assessed as $cid => $a) {
            if (!isset($recommended[$cid]) && $a['source'] === 'manual' && $a['control_active']) {
                $inputs[] = self::input($a, $a, false);
            }
        }
        return $inputs;
    }

    private static function input(array $control, ?array $a, bool $mandatory): ControlAssessmentInput
    {
        return new ControlAssessmentInput(
            (int) $control['control_id'],
            (string) $control['title'],
            ControlCategory::from($control['category']),
            $mandatory,
            ControlStatus::from($a['status'] ?? 'not_started'),
            $a['effectiveness_pct'] ?? null,
            !empty($a['evidence_path']),
            $a !== null && $a['expiry_date'] !== null ? substr((string) $a['expiry_date'], 0, 10) : null,
            (bool) $control['verification_required'],
            $a !== null && $a['verified_at'] !== null,
        );
    }

    /** @return array{0: ClearanceResult, 1: ResidualRiskResult} */
    public function evaluate(array $entity, ScoringConfig $config): array
    {
        $clear = $this->clearance->evaluate($this->clearanceInputs($entity), $config, $this->clock->today());
        $result = $this->calculator->calculate(
            Rating::from($entity['inherent_rating']),
            (float) $entity['inherent_score'],
            $clear->cleared,
            (bool) $entity['negligible_residual_confirmed'],
            $config,
            $entity['entity_type'] === EntityType::Vendor->value,
        );
        return [$clear, $result];
    }

    /** Recompute and store the residual fields; audits when anything changed. */
    public function persist(string $actor, int $entityId, ScoringConfig $config): ?ResidualRiskResult
    {
        $entity = $this->entities->find($entityId);
        if ($entity === null || $entity['inherent_rating'] === null) {
            return null;
        }
        [, $r] = $this->evaluate($entity, $config);

        $new = [
            'residual_rating' => $r->rating->value,
            'residual_score' => number_format($r->residualScore, 2, '.', ''),
            'residual_score_100' => $r->residualScore100,
            'risk_reduced_pct' => number_format($r->riskReducedPct, 2, '.', ''),
            'cleared_categories' => implode(',', array_map(fn (ControlCategory $c) => $c->value, $r->clearedCategories)),
        ];
        $old = [
            'residual_rating' => $entity['residual_rating'],
            'residual_score' => $entity['residual_score'],
            'residual_score_100' => $entity['residual_score_100'] === null ? null : (int) $entity['residual_score_100'],
            'risk_reduced_pct' => $entity['risk_reduced_pct'],
            'cleared_categories' => (string) $entity['cleared_categories'],
        ];
        $changed = $old['residual_rating'] !== $new['residual_rating']
            || (float) $old['residual_score'] !== (float) $new['residual_score']
            || $old['cleared_categories'] !== $new['cleared_categories']
            || $old['residual_score_100'] !== $new['residual_score_100'];

        $this->entities->update($entityId, $new + ['residual_calculated_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
        if ($changed) {
            $this->audit->record($actor, 'risk_entities', $entityId, 'residual_recalculated', $old, $new + ['rule' => $r->ruleCode]);
        }
        return $r;
    }
}
