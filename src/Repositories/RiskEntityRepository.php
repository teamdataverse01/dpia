<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Engine\InherentRiskResult;
use App\Infrastructure\PdoConnection;

/** risk_entities with their type-specific profile, factor inputs and score breakdown. */
final class RiskEntityRepository
{
    private const SELECT = <<<'SQL'
        SELECT e.*, d.name AS department_name,
               vp.legal_name, vp.service_description, vp.contract_start, vp.contract_end, vp.subprocessors,
               vp.contact_name, vp.contact_email, vp.vendor_portal_user_id,
               pa.purpose, pa.description, pa.retention_period,
               t.activity_name AS template_name
          FROM risk_entities e
          LEFT JOIN departments d ON d.id = e.department_id
          LEFT JOIN vendor_profiles vp ON vp.risk_entity_id = e.id
          LEFT JOIN processing_activities pa ON pa.risk_entity_id = e.id
          LEFT JOIN activity_templates t ON t.id = e.template_id
        SQL;

    public function __construct(private readonly PdoConnection $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::SELECT . ' WHERE e.id = :id', ['id' => $id]);
    }

    public function create(array $entity): int
    {
        return $this->db->insert('risk_entities', $entity);
    }

    public function update(int $id, array $values): void
    {
        $this->db->update('risk_entities', $values, 'id = :id', ['id' => $id]);
    }

    public function createVendorProfile(int $entityId, array $profile): void
    {
        $this->db->insertNoId('vendor_profiles', ['risk_entity_id' => $entityId] + $profile);
    }

    public function updateVendorProfile(int $entityId, array $profile): void
    {
        $this->db->update('vendor_profiles', $profile, 'risk_entity_id = :id', ['id' => $entityId]);
    }

    public function createProcessingActivity(int $entityId, array $profile): void
    {
        $this->db->insertNoId('processing_activities', ['risk_entity_id' => $entityId] + $profile);
    }

    public function updateProcessingActivity(int $entityId, array $profile): void
    {
        $this->db->update('processing_activities', $profile, 'risk_entity_id = :id', ['id' => $entityId]);
    }

    /**
     * @param array{entity_type?:string,department_id?:int,rating?:string,basis?:string,status?:string,owner?:string,
     *              q?:string,factor?:string,from?:string,to?:string,active_only?:bool,cleared?:string,
     *              template_id?:int,ids?:list<int>} $f
     * @param array{owner_only:bool,department_ids:list<int>|null,user_id:string} $scope
     * @return list<array<string,mixed>>
     */
    public function list(array $f, array $scope, int $limit = 500): array
    {
        [$where, $params] = self::filterSql($f, $scope);
        return $this->db->fetchAll(
            self::SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.updated_at DESC, e.id DESC LIMIT ' . max(1, min(2000, $limit)),
            $params,
        );
    }

    /**
     * Shared WHERE builder (also used by the dashboard so counts always match the list).
     *
     * @return array{0: list<string>, 1: array<string,mixed>}
     */
    public static function filterSql(array $f, array $scope, string $alias = 'e'): array
    {
        $w = [];
        $p = [];
        $basisCol = ($f['basis'] ?? 'residual') === 'inherent' ? "{$alias}.inherent_rating" : "{$alias}.residual_rating";

        if (!empty($f['entity_type'])) {
            $w[] = "{$alias}.entity_type = :f_type";
            $p['f_type'] = $f['entity_type'];
        }
        if (!empty($f['department_id'])) {
            if ((int) $f['department_id'] === -1) {
                $w[] = "{$alias}.department_id IS NULL";
            } else {
                $w[] = "{$alias}.department_id = :f_dept";
                $p['f_dept'] = (int) $f['department_id'];
            }
        }
        if (!empty($f['rating'])) {
            $w[] = "{$basisCol} = :f_rating";
            $p['f_rating'] = $f['rating'];
        }
        if (!empty($f['status'])) {
            $w[] = "{$alias}.status = :f_status";
            $p['f_status'] = $f['status'];
        }
        if (!empty($f['owner'])) {
            $w[] = "{$alias}.owner_portal_user_id = :f_owner";
            $p['f_owner'] = $f['owner'];
        }
        if (!empty($f['q'])) {
            $w[] = "LOWER({$alias}.name) LIKE :f_q";
            $p['f_q'] = '%' . strtolower(str_replace(['%', '_'], ['\%', '\_'], $f['q'])) . '%';
        }
        if (!empty($f['from'])) {
            $w[] = "{$alias}.created_at >= :f_from";
            $p['f_from'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $w[] = "{$alias}.created_at <= :f_to";
            $p['f_to'] = $f['to'] . ' 23:59:59';
        }
        if (!empty($f['template_id'])) {
            $w[] = "{$alias}.template_id = :f_tpl";
            $p['f_tpl'] = (int) $f['template_id'];
        }
        if (!empty($f['factor'])) {
            // Latest breakdown for the entity scored this factor 4 or 5.
            $w[] = "EXISTS (SELECT 1 FROM factor_score_breakdown b
                             WHERE b.risk_entity_id = {$alias}.id AND b.factor = :f_factor AND b.factor_score >= 4
                               AND b.calculated_at = (SELECT MAX(b2.calculated_at) FROM factor_score_breakdown b2 WHERE b2.risk_entity_id = {$alias}.id))";
            $p['f_factor'] = $f['factor'];
        }
        if (!empty($f['cleared'])) {
            if ($f['cleared'] === 'none') {
                $w[] = "({$alias}.cleared_categories IS NULL OR {$alias}.cleared_categories = '')";
            } else {
                $w[] = "{$alias}.cleared_categories = :f_cleared";
                $p['f_cleared'] = $f['cleared'];
            }
        }
        if (!empty($f['active_only'])) {
            // "Active" = scored and not rejected (PRD 11.1; there is no archived state in v1).
            $w[] = "{$alias}.status <> 'rejected' AND {$basisCol} IS NOT NULL";
        }
        if (!empty($f['open_only'])) {
            $w[] = "{$alias}.status <> 'rejected'";
        }
        if (isset($f['ids'])) {
            $ids = array_values(array_map('intval', $f['ids']));
            if ($ids === []) {
                $w[] = '1 = 0';
            } else {
                $ph = [];
                foreach ($ids as $i => $id) {
                    $ph[] = ':f_id' . $i;
                    $p['f_id' . $i] = $id;
                }
                $w[] = "{$alias}.id IN (" . implode(', ', $ph) . ')';
            }
        }

        if ($scope['owner_only'] ?? false) {
            $w[] = "({$alias}.owner_portal_user_id = :s_user OR EXISTS (SELECT 1 FROM vendor_profiles svp WHERE svp.risk_entity_id = {$alias}.id AND svp.vendor_portal_user_id = :s_user2))";
            $p['s_user'] = $scope['user_id'];
            $p['s_user2'] = $scope['user_id'];
        }
        if (($scope['department_ids'] ?? null) !== null) {
            $ph = [];
            foreach ($scope['department_ids'] as $i => $d) {
                $ph[] = ':s_d' . $i;
                $p['s_d' . $i] = (int) $d;
            }
            $w[] = $ph === [] ? '1 = 0' : "{$alias}.department_id IN (" . implode(', ', $ph) . ')';
        }
        return [$w, $p];
    }

    /** Vendors that can be linked to a DPIA (they have at least an inherent score). */
    public function vendorsForPicker(): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.name, e.status, e.inherent_score, e.inherent_rating, e.residual_rating, e.residual_score, e.residual_score_100,
                    d.name AS department_name
               FROM risk_entities e LEFT JOIN departments d ON d.id = e.department_id
              WHERE e.entity_type = 'vendor' AND e.inherent_score IS NOT NULL AND e.status <> 'rejected'
              ORDER BY e.name",
        );
    }

    // --- factor inputs ---------------------------------------------------------

    public function factorInputs(int $entityId): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM risk_factor_inputs WHERE risk_entity_id = :id', ['id' => $entityId]);
        if ($row === null) {
            return null;
        }
        $row['data_type'] = json_decode((string) $row['data_type'], true) ?: [];
        $row['involves_data_sharing'] = (bool) $row['involves_data_sharing'];
        $row['vendor_risk_ref'] = $row['vendor_risk_ref'] === null ? null : (int) $row['vendor_risk_ref'];
        return $row;
    }

    /** Portable upsert (no ON CONFLICT). */
    public function saveFactorInputs(int $entityId, array $values): void
    {
        $values['data_type'] = json_encode(array_values($values['data_type']), JSON_THROW_ON_ERROR);
        $exists = $this->db->fetchValue('SELECT id FROM risk_factor_inputs WHERE risk_entity_id = :id', ['id' => $entityId]);
        if ($exists === null) {
            $this->db->insert('risk_factor_inputs', ['risk_entity_id' => $entityId] + $values);
        } else {
            $this->db->update('risk_factor_inputs', $values, 'risk_entity_id = :id', ['id' => $entityId]);
        }
    }

    /** DPIAs whose factor inputs reference this vendor. */
    public function dpiasLinkedToVendor(int $vendorId): array
    {
        return $this->db->fetchAll(
            'SELECT e.id, e.name, e.status, e.inherent_rating, e.residual_rating
               FROM risk_factor_inputs f JOIN risk_entities e ON e.id = f.risk_entity_id
              WHERE f.vendor_risk_ref = :v ORDER BY e.name',
            ['v' => $vendorId],
        );
    }

    // --- breakdown --------------------------------------------------------------

    public function insertBreakdown(int $entityId, InherentRiskResult $r, string $calculatedAt): void
    {
        foreach ($r->breakdown as $c) {
            $this->db->insert('factor_score_breakdown', [
                'risk_entity_id' => $entityId,
                'factor' => $c->factor->value,
                'selected_option' => $c->selectedOption,
                'factor_score' => $c->score,
                'weight_used' => number_format($c->weightUsed, 4, '.', ''),
                'weighted_value' => number_format($c->weightedValue, 4, '.', ''),
                'framework_version' => $r->configVersion,
                'calculated_at' => $calculatedAt,
            ]);
        }
    }

    /** @return list<array<string,mixed>> the most recent calculation's rows */
    public function latestBreakdown(int $entityId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM factor_score_breakdown
              WHERE risk_entity_id = :id
                AND calculated_at = (SELECT MAX(calculated_at) FROM factor_score_breakdown WHERE risk_entity_id = :id2)
              ORDER BY weight_used DESC, id',
            ['id' => $entityId, 'id2' => $entityId],
        );
    }

    /** @return list<array{calculated_at:string,framework_version:?int,score:float}> */
    public function scoreHistory(int $entityId): array
    {
        return $this->db->fetchAll(
            'SELECT calculated_at, MAX(framework_version) AS framework_version, ROUND(SUM(weighted_value), 2) AS score
               FROM factor_score_breakdown WHERE risk_entity_id = :id
              GROUP BY calculated_at ORDER BY calculated_at DESC',
            ['id' => $entityId],
        );
    }
}
