<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\EntityType;
use App\Domain\Rating;
use App\Engine\RiskProfile;
use App\Engine\RiskTriggerEvaluator;
use App\Repositories\ControlRepository;
use App\Repositories\RiskEntityRepository;

/** Stage 4 — recommends library controls whose risk_trigger matches the assessment (PRD 9.1). */
final class RecommendationService
{
    public function __construct(
        private readonly ControlRepository $controls,
        private readonly RiskEntityRepository $entities,
        private readonly FactorCatalog $catalog,
        private readonly RiskTriggerEvaluator $triggers,
    ) {
    }

    public function profile(array $entity): ?RiskProfile
    {
        if ($entity['inherent_rating'] === null) {
            return null;
        }
        $factors = $this->entities->factorInputs((int) $entity['id']);
        if ($factors === null) {
            return null;
        }
        $type = EntityType::from($entity['entity_type']);
        return new RiskProfile(
            $type,
            FactorCatalog::scoreMap($this->catalog->selections($type, $factors)),
            (bool) $factors['involves_data_sharing'],
            Rating::from($entity['inherent_rating']),
            $factors['data_type'],
        );
    }

    /**
     * Recommended controls with the reason each was recommended.
     *
     * @return array<int,array{control: array<string,mixed>, source: string, reason: string}> keyed by control id
     */
    public function recommended(array $entity): array
    {
        $profile = $this->profile($entity);
        if ($profile === null) {
            return [];
        }
        $out = [];
        foreach ($this->controls->all() as $c) {
            if ($this->triggers->matches($c['risk_trigger'], $profile)) {
                $out[$c['id']] = [
                    'control' => $c,
                    'source' => 'recommended',
                    'reason' => 'Recommended because ' . $this->triggers->describe($c['risk_trigger']) . '.',
                ];
            }
        }
        if ($entity['template_id'] !== null) {
            foreach ($this->controls->templateControlIds((int) $entity['template_id']) as $cid) {
                if (isset($out[$cid])) {
                    $out[$cid]['reason'] .= ' Also a standard control for the "' . $entity['template_name'] . '" template.';
                    continue;
                }
                $c = $this->controls->find($cid);
                if ($c !== null && $c['active']) {
                    $out[$cid] = [
                        'control' => $c,
                        'source' => 'template',
                        'reason' => 'Standard control for the "' . $entity['template_name'] . '" template.',
                    ];
                }
            }
        }
        return $out;
    }
}
