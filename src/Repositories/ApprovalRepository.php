<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Infrastructure\PdoConnection;

final class ApprovalRepository
{
    public function __construct(private readonly PdoConnection $db)
    {
    }

    public function create(array $values): int
    {
        return $this->db->insert('approvals', $values);
    }

    /** @return list<array<string,mixed>> newest first */
    public function forEntity(int $entityId): array
    {
        return $this->db->fetchAll('SELECT * FROM approvals WHERE risk_entity_id = :id ORDER BY decided_at DESC, id DESC', ['id' => $entityId]);
    }

    /** Approvals recorded in the current round (since the last submission). */
    public function since(int $entityId, string $since): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM approvals WHERE risk_entity_id = :id AND decided_at >= :since ORDER BY decided_at, id',
            ['id' => $entityId, 'since' => $since],
        );
    }

    public function latestAcceptance(int $entityId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM approvals WHERE risk_entity_id = :id AND decision = 'risk_accepted' ORDER BY decided_at DESC, id DESC LIMIT 1",
            ['id' => $entityId],
        );
    }
}
