<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Appends to audit_log. Call inside the same transaction as the change it records (PRD 12).
 * There is deliberately no update or delete method.
 */
final class AuditLogger
{
    public function __construct(private readonly PdoConnection $db)
    {
    }

    public function record(
        string $actorPortalUserId,
        string $entityTable,
        int $entityId,
        string $action,
        mixed $old = null,
        mixed $new = null,
    ): void {
        $this->db->insert('audit_log', [
            'actor_portal_user_id' => $actorPortalUserId,
            'entity_table' => $entityTable,
            'entity_id' => $entityId,
            'action' => $action,
            'old_value' => self::encode($old),
            'new_value' => self::encode($new),
        ]);
    }

    private static function encode(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            return $v;
        }
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
