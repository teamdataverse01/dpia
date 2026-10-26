<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Engine\TemplateCandidate;
use App\Infrastructure\PdoConnection;

final class TemplateRepository
{
    private const SELECT = <<<'SQL'
        SELECT t.*, d.name AS department_name, v.name AS vendor_name
          FROM activity_templates t
          JOIN departments d ON d.id = t.department_id
          LEFT JOIN risk_entities v ON v.id = t.default_vendor_entity_id
        SQL;

    public function __construct(private readonly PdoConnection $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(self::SELECT . ' WHERE t.id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function search(?int $departmentId, ?string $q): array
    {
        $w = [];
        $p = [];
        if ($departmentId) {
            $w[] = 't.department_id = :d';
            $p['d'] = $departmentId;
        }
        if ($q !== null && trim($q) !== '') {
            $w[] = 'LOWER(t.activity_name) LIKE :q';
            $p['q'] = '%' . strtolower(str_replace(['%', '_'], ['\%', '\_'], trim($q))) . '%';
        }
        return $this->db->fetchAll(self::SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY d.name, t.activity_name', $p);
    }

    /** @return list<TemplateCandidate> */
    public function candidates(): array
    {
        return array_map(fn (array $r) => self::candidate($r), $this->search(null, null));
    }

    public static function candidate(array $r): TemplateCandidate
    {
        return new TemplateCandidate(
            (int) $r['id'],
            (int) $r['department_id'],
            (string) $r['department_name'],
            (string) $r['activity_name'],
            (string) $r['data_type_signature'],
            (string) $r['data_subject_signature'],
            (string) $r['characteristics_signature'],
            (int) $r['framework_version'],
            substr((string) $r['last_reviewed_at'], 0, 10),
        );
    }

    public function create(array $values): int
    {
        return $this->db->insert('activity_templates', $values);
    }

    public function update(int $id, array $values): void
    {
        $this->db->update('activity_templates', $values, 'id = :id', ['id' => $id]);
    }

    /** @param list<int> $controlIds */
    public function setControls(int $templateId, array $controlIds): void
    {
        $this->db->execute('DELETE FROM activity_template_controls WHERE template_id = :t', ['t' => $templateId]);
        foreach (array_unique($controlIds) as $cid) {
            $this->db->insertNoId('activity_template_controls', ['template_id' => $templateId, 'control_id' => (int) $cid]);
        }
    }

    public function existsByName(int $departmentId, string $name): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM activity_templates WHERE department_id = :d AND LOWER(activity_name) = LOWER(:n)',
            ['d' => $departmentId, 'n' => $name],
        ) !== null;
    }
}
