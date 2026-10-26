<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Infrastructure\PdoConnection;

final class RemediationRepository
{
    private const SELECT = <<<'SQL'
        SELECT r.*, e.name AS entity_name, e.entity_type, e.department_id, e.owner_portal_user_id AS entity_owner,
               e.residual_rating, d.name AS department_name, c.title AS control_title, c.category AS control_category
          FROM remediation_actions r
          JOIN risk_entities e ON e.id = r.risk_entity_id
          LEFT JOIN departments d ON d.id = e.department_id
          LEFT JOIN controls c ON c.id = r.control_id
        SQL;

    public function __construct(private readonly PdoConnection $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::SELECT . ' WHERE r.id = :id', ['id' => $id]);
    }

    public function create(array $values): int
    {
        return $this->db->insert('remediation_actions', $values);
    }

    public function update(int $id, array $values): void
    {
        $this->db->update('remediation_actions', $values, 'id = :id', ['id' => $id]);
    }

    /**
     * @param array{risk_entity_id?:int,status?:string,department_id?:int,owner?:string,entity_type?:string,priority?:string} $f
     *        status may be "overdue" (derived) or "open_all" (open + in_progress + overdue)
     */
    public function list(array $f, array $scope, string $today): array
    {
        $w = [];
        $p = [];
        if (!empty($f['risk_entity_id'])) {
            $w[] = 'r.risk_entity_id = :eid';
            $p['eid'] = (int) $f['risk_entity_id'];
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'overdue') {
                $w[] = "r.status IN ('open', 'in_progress') AND r.target_date < :today";
                $p['today'] = $today;
            } elseif ($f['status'] === 'open_all') {
                $w[] = "r.status IN ('open', 'in_progress')";
            } elseif (in_array($f['status'], ['open', 'in_progress'], true)) {
                // stored open/in_progress that are NOT overdue
                $w[] = 'r.status = :st AND r.target_date >= :today';
                $p['st'] = $f['status'];
                $p['today'] = $today;
            } else {
                $w[] = 'r.status = :st';
                $p['st'] = $f['status'];
            }
        }
        if (!empty($f['priority'])) {
            $w[] = 'r.priority = :prio';
            $p['prio'] = $f['priority'];
        }
        if (!empty($f['owner'])) {
            $w[] = 'r.owner_portal_user_id = :owner';
            $p['owner'] = $f['owner'];
        }
        [$ew, $ep] = RiskEntityRepository::filterSql(
            array_intersect_key($f, array_flip(['entity_type', 'department_id', 'from', 'to'])),
            $scope,
        );
        if (($scope['owner_only'] ?? false) && isset($scope['user_id'])) {
            // owners of the risk OR owners of the action can see it
            $ew = array_filter($ew, fn ($c) => !str_contains($c, ':s_user'));
            $ew[] = '(e.owner_portal_user_id = :s_user OR r.owner_portal_user_id = :s_user2)';
            $ep['s_user'] = $scope['user_id'];
            $ep['s_user2'] = $scope['user_id'];
        }
        $w = array_merge($w, array_values($ew));
        $p += $ep;
        return $this->db->fetchAll(
            self::SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY r.target_date, r.id',
            $p,
        );
    }

    public function openForControl(int $entityId, int $controlId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM remediation_actions WHERE risk_entity_id = :e AND control_id = :c AND status IN ('open', 'in_progress')",
            ['e' => $entityId, 'c' => $controlId],
        );
    }
}
