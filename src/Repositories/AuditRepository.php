<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Infrastructure\PdoConnection;

/** Read side of audit_log (writes go through Infrastructure\AuditLogger only). */
final class AuditRepository
{
    public function __construct(private readonly PdoConnection $db)
    {
    }

    /**
     * @param array{entity_table?:string,entity_id?:int,actor?:string,action?:string,from?:string,to?:string} $f
     */
    public function list(array $f, int $limit = 200, int $offset = 0): array
    {
        $w = [];
        $p = [];
        foreach (['entity_table' => 'entity_table', 'actor' => 'actor_portal_user_id', 'action' => 'action'] as $key => $col) {
            if (!empty($f[$key])) {
                $w[] = "{$col} = :{$key}";
                $p[$key] = $f[$key];
            }
        }
        if (!empty($f['entity_id'])) {
            $w[] = 'entity_id = :entity_id';
            $p['entity_id'] = (int) $f['entity_id'];
        }
        if (!empty($f['from'])) {
            $w[] = 'occurred_at >= :from';
            $p['from'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $w[] = 'occurred_at <= :to';
            $p['to'] = $f['to'] . ' 23:59:59';
        }
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        return $this->db->fetchAll(
            'SELECT * FROM audit_log' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY occurred_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}",
            $p,
        );
    }

    /** Everything recorded about one assessment, including its controls, actions and approvals. */
    public function historyForEntity(int $entityId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM audit_log
              WHERE (entity_table = 'risk_entities' AND entity_id = :id)
                 OR (entity_table IN ('control_assessments', 'remediation_actions', 'approvals', 'risk_factor_inputs')
                     AND (new_value LIKE :n1 OR new_value LIKE :n2))
              ORDER BY occurred_at DESC, id DESC LIMIT 300",
            // child rows carry "risk_entity_id":<id> in their JSON; match the id exactly (followed by , or })
            ['id' => $entityId, 'n1' => '%"risk_entity_id":' . $entityId . ',%', 'n2' => '%"risk_entity_id":' . $entityId . '}%'],
        );
    }
}
