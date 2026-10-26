<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Engine\ScoringConfig;
use App\Infrastructure\PdoConnection;

final class ConfigRepository
{
    public function __construct(private readonly PdoConnection $db)
    {
    }

    public function active(): ScoringConfig
    {
        $row = $this->db->fetchOne('SELECT version, config_json FROM scoring_config WHERE active = TRUE ORDER BY version DESC LIMIT 1');
        if ($row === null) {
            throw new \RuntimeException('No active scoring_config. Run the seeds.');
        }
        return ScoringConfig::fromArray(json_decode($row['config_json'], true, 512, JSON_THROW_ON_ERROR), (int) $row['version']);
    }

    public function byVersion(int $version): ?ScoringConfig
    {
        $row = $this->db->fetchOne('SELECT version, config_json FROM scoring_config WHERE version = :v', ['v' => $version]);
        return $row === null ? null : ScoringConfig::fromArray(json_decode($row['config_json'], true, 512, JSON_THROW_ON_ERROR), (int) $row['version']);
    }

    /** @return list<array<string,mixed>> */
    public function history(): array
    {
        return $this->db->fetchAll('SELECT id, version, active, change_note, created_by, created_at FROM scoring_config ORDER BY version DESC');
    }

    /** Insert a new version and make it the only active one. Caller wraps in a transaction. */
    public function createVersion(array $configData, string $createdBy, ?string $note): int
    {
        $next = (int) $this->db->fetchValue('SELECT COALESCE(MAX(version), 0) + 1 FROM scoring_config');
        $this->db->execute('UPDATE scoring_config SET active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE active = TRUE');
        $this->db->insert('scoring_config', [
            'version' => $next,
            'config_json' => json_encode($configData, JSON_THROW_ON_ERROR),
            'active' => true,
            'change_note' => $note,
            'created_by' => $createdBy,
        ]);
        return $next;
    }
}
