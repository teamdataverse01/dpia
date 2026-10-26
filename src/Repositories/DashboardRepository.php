<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Infrastructure\PdoConnection;

/**
 * Read-only aggregate queries for the dashboard (PRD 11). Every query reuses
 * RiskEntityRepository::filterSql so dashboard numbers always match the risk list.
 */
final class DashboardRepository
{
    public function __construct(private readonly PdoConnection $db)
    {
    }

    private static function basisCol(array $f): string
    {
        return ($f['basis'] ?? 'residual') === 'inherent' ? 'e.inherent_rating' : 'e.residual_rating';
    }

    /** @return array<string,int> rating => count (active only) */
    public function ratingCounts(array $f, array $scope): array
    {
        [$w, $p] = RiskEntityRepository::filterSql($f + ['active_only' => true], $scope);
        $col = self::basisCol($f);
        $rows = $this->db->fetchAll("SELECT {$col} AS rating, COUNT(*) AS n FROM risk_entities e WHERE " . implode(' AND ', $w) . " GROUP BY {$col}", $p);
        $out = ['high' => 0, 'medium' => 0, 'low' => 0, 'very_low' => 0];
        foreach ($rows as $r) {
            $out[$r['rating']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array{department_id:?int,department:string,rating:string,n:int}> */
    public function byDepartment(array $f, array $scope): array
    {
        [$w, $p] = RiskEntityRepository::filterSql($f + ['active_only' => true], $scope);
        $col = self::basisCol($f);
        return array_map(fn ($r) => [
            'department_id' => $r['department_id'] === null ? null : (int) $r['department_id'],
            'department' => $r['department'] ?? 'Unassigned',
            'rating' => $r['rating'],
            'n' => (int) $r['n'],
        ], $this->db->fetchAll(
            "SELECT e.department_id, d.name AS department, {$col} AS rating, COUNT(*) AS n
               FROM risk_entities e LEFT JOIN departments d ON d.id = e.department_id
              WHERE " . implode(' AND ', $w) . "
              GROUP BY e.department_id, d.name, {$col}",
            $p,
        ));
    }

    public function averageRiskReduced(array $f, array $scope): ?float
    {
        [$w, $p] = RiskEntityRepository::filterSql($f + ['active_only' => true], $scope);
        $v = $this->db->fetchValue('SELECT AVG(e.risk_reduced_pct) FROM risk_entities e WHERE ' . implode(' AND ', $w) . ' AND e.risk_reduced_pct IS NOT NULL', $p);
        return $v === null ? null : round((float) $v, 1);
    }

    public function pendingApprovals(array $f, array $scope): array
    {
        [$w, $p] = RiskEntityRepository::filterSql($f + ['status' => 'pending_approval'], $scope);
        return $this->db->fetchAll(
            'SELECT e.id, e.name, e.entity_type, e.residual_rating, e.submitted_at, d.name AS department_name
               FROM risk_entities e LEFT JOIN departments d ON d.id = e.department_id
              WHERE ' . implode(' AND ', $w) . ' ORDER BY e.submitted_at',
            $p,
        );
    }

    /**
     * Risk acceptances, vendor contracts and evidence expiring within $days (PRD 11.3).
     *
     * @return list<array{kind:string,entity_id:int,entity_name:string,item:string,expires:string}>
     */
    public function expiring(array $f, array $scope, string $today, string $until): array
    {
        [$w, $p] = RiskEntityRepository::filterSql($f + ['open_only' => true], $scope);
        $where = implode(' AND ', $w);
        $p += ['t1' => $today, 'u1' => $until];

        $acc = $this->db->fetchAll(
            "SELECT 'risk_acceptance' AS kind, e.id AS entity_id, e.name AS entity_name, 'Risk acceptance' AS item, a.accepted_until AS expires
               FROM risk_entities e
               JOIN approvals a ON a.risk_entity_id = e.id AND a.decision = 'risk_accepted'
              WHERE {$where} AND e.status IN ('risk_accepted', 'monitoring', 'reassessment_due')
                AND a.decided_at = (SELECT MAX(a2.decided_at) FROM approvals a2 WHERE a2.risk_entity_id = e.id AND a2.decision = 'risk_accepted')
                AND a.accepted_until BETWEEN :t1 AND :u1",
            $p,
        );
        $con = $this->db->fetchAll(
            "SELECT 'contract' AS kind, e.id AS entity_id, e.name AS entity_name, 'Vendor contract' AS item, vp.contract_end AS expires
               FROM risk_entities e JOIN vendor_profiles vp ON vp.risk_entity_id = e.id
              WHERE {$where} AND vp.contract_end BETWEEN :t1 AND :u1",
            $p,
        );
        $ev = $this->db->fetchAll(
            "SELECT 'evidence' AS kind, e.id AS entity_id, e.name AS entity_name, c.title AS item, ca.expiry_date AS expires
               FROM risk_entities e
               JOIN control_assessments ca ON ca.risk_entity_id = e.id
               JOIN controls c ON c.id = ca.control_id
              WHERE {$where} AND ca.evidence_path IS NOT NULL AND ca.expiry_date BETWEEN :t1 AND :u1",
            $p,
        );
        $all = array_merge($acc, $con, $ev);
        usort($all, fn ($a, $b) => [$a['expires'], $a['entity_name']] <=> [$b['expires'], $b['entity_name']]);
        return $all;
    }

    /**
     * Controls most often failing the clearance criteria across active assessments.
     *
     * @return list<array{control_id:int,title:string,category:string,n:int}>
     */
    public function topControlGaps(array $f, array $scope, int $minEffectiveness, string $today, int $limit = 5): array
    {
        [$w, $p] = RiskEntityRepository::filterSql($f + ['open_only' => true], $scope);
        $p += ['min_eff' => $minEffectiveness, 'today_gap' => $today];
        return array_map(fn ($r) => ['control_id' => (int) $r['control_id'], 'title' => $r['title'], 'category' => $r['category'], 'n' => (int) $r['n']], $this->db->fetchAll(
            "SELECT c.id AS control_id, c.title, c.category, COUNT(*) AS n
               FROM control_assessments ca
               JOIN controls c ON c.id = ca.control_id
               JOIN risk_entities e ON e.id = ca.risk_entity_id
              WHERE " . implode(' AND ', $w) . "
                AND (ca.status <> 'implemented'
                     OR ca.effectiveness_pct IS NULL OR ca.effectiveness_pct < :min_eff
                     OR ca.evidence_path IS NULL
                     OR ca.expiry_date < :today_gap
                     OR (c.verification_required = TRUE AND ca.verified_at IS NULL))
              GROUP BY c.id, c.title, c.category
              ORDER BY COUNT(*) DESC, c.id
              LIMIT " . max(1, $limit),
            $p,
        ));
    }

    /**
     * For each factor, how many items in $rating had that factor scored 4–5 in their latest calculation.
     *
     * @return array<string,int>
     */
    public function highScoringFactors(array $f, array $scope, ?string $rating, ?array $ratings = null): array
    {
        $ff = $f + ['active_only' => true];
        if ($rating !== null) {
            $ff['rating'] = $rating;
        }
        [$w, $p] = RiskEntityRepository::filterSql($ff, $scope);
        if ($ratings !== null) {
            $col = self::basisCol($f);
            $ph = [];
            foreach ($ratings as $i => $r) {
                $ph[] = ':hr' . $i;
                $p['hr' . $i] = $r;
            }
            $w[] = "{$col} IN (" . implode(', ', $ph) . ')';
        }
        $rows = $this->db->fetchAll(
            'SELECT b.factor, COUNT(DISTINCT e.id) AS n
               FROM risk_entities e
               JOIN factor_score_breakdown b ON b.risk_entity_id = e.id
              WHERE ' . implode(' AND ', $w) . '
                AND b.factor_score >= 4
                AND b.calculated_at = (SELECT MAX(b2.calculated_at) FROM factor_score_breakdown b2 WHERE b2.risk_entity_id = e.id)
              GROUP BY b.factor ORDER BY COUNT(DISTINCT e.id) DESC, b.factor',
            $p,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['factor']] = (int) $r['n'];
        }
        return $out;
    }

    /** Rows with cleared categories and reduction, for the control-effectiveness story. */
    public function clearanceStats(array $f, array $scope): array
    {
        [$w, $p] = RiskEntityRepository::filterSql($f + ['active_only' => true], $scope);
        $col = self::basisCol(['basis' => 'residual']);
        return $this->db->fetchAll(
            "SELECT e.id, e.cleared_categories, e.risk_reduced_pct, {$col} AS residual_rating, e.inherent_rating
               FROM risk_entities e WHERE " . implode(' AND ', $w),
            $p,
        );
    }

    /** @return list<array{snapshot_date:string,high:int,total:int}> */
    public function snapshotSeries(array $f): array
    {
        $w = [];
        $p = [];
        if (!empty($f['entity_type'])) {
            $w[] = 'entity_type = :t';
            $p['t'] = $f['entity_type'];
        }
        if (!empty($f['department_id'])) {
            $w[] = 'department_id = :d';
            $p['d'] = (int) $f['department_id'];
        }
        $col = ($f['basis'] ?? 'residual') === 'inherent' ? 'inherent_rating' : 'residual_rating';
        return array_map(fn ($r) => ['snapshot_date' => substr((string) $r['snapshot_date'], 0, 10), 'high' => (int) $r['high'], 'total' => (int) $r['total']], $this->db->fetchAll(
            "SELECT snapshot_date,
                    SUM(CASE WHEN {$col} = 'high' THEN risk_count ELSE 0 END) AS high,
                    SUM(risk_count) AS total
               FROM risk_snapshots" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . '
              GROUP BY snapshot_date ORDER BY snapshot_date',
            $p,
        ));
    }

    public function writeSnapshot(string $date): int
    {
        $this->db->execute('DELETE FROM risk_snapshots WHERE snapshot_date = :d', ['d' => $date]);
        return $this->db->execute(
            "INSERT INTO risk_snapshots (snapshot_date, department_id, entity_type, residual_rating, inherent_rating, risk_count)
             SELECT CAST(:d AS DATE), department_id, entity_type, residual_rating, inherent_rating, COUNT(*)
               FROM risk_entities
              WHERE status <> 'rejected' AND residual_rating IS NOT NULL AND inherent_rating IS NOT NULL
              GROUP BY department_id, entity_type, residual_rating, inherent_rating",
            ['d' => $date],
        );
    }
}
