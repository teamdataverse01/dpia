<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Infrastructure\PdoConnection;

/** Control library (controls) and per-assessment control_assessments. */
final class ControlRepository
{
    public function __construct(private readonly PdoConnection $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $activeOnly = true): array
    {
        return array_map([self::class, 'castControl'], $this->db->fetchAll(
            'SELECT * FROM controls' . ($activeOnly ? ' WHERE active = TRUE' : '') . " ORDER BY CASE category WHEN 'policy' THEN 1 WHEN 'process' THEN 2 ELSE 3 END, id",
        ));
    }

    public function find(int $id): ?array
    {
        $r = $this->db->fetchOne('SELECT * FROM controls WHERE id = :id', ['id' => $id]);
        return $r === null ? null : self::castControl($r);
    }

    public function create(array $values): int
    {
        return $this->db->insert('controls', $values);
    }

    public function update(int $id, array $values): void
    {
        $this->db->update('controls', $values, 'id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> control_assessments joined with their control */
    public function assessmentsFor(int $entityId): array
    {
        return array_map([self::class, 'castAssessment'], $this->db->fetchAll(
            "SELECT ca.*, c.category, c.title, c.description, c.risk_trigger, c.mandatory, c.expected_evidence,
                    c.owner_role, c.verification_required, c.active AS control_active
               FROM control_assessments ca JOIN controls c ON c.id = ca.control_id
              WHERE ca.risk_entity_id = :id
              ORDER BY CASE c.category WHEN 'policy' THEN 1 WHEN 'process' THEN 2 ELSE 3 END, c.id",
            ['id' => $entityId],
        ));
    }

    public function assessment(int $entityId, int $controlId): ?array
    {
        $r = $this->db->fetchOne(
            'SELECT ca.*, c.category, c.title, c.mandatory, c.verification_required
               FROM control_assessments ca JOIN controls c ON c.id = ca.control_id
              WHERE ca.risk_entity_id = :e AND ca.control_id = :c',
            ['e' => $entityId, 'c' => $controlId],
        );
        return $r === null ? null : self::castAssessment($r);
    }

    public function createAssessment(int $entityId, int $controlId): void
    {
        $this->db->insert('control_assessments', ['risk_entity_id' => $entityId, 'control_id' => $controlId]);
    }

    public function updateAssessment(int $entityId, int $controlId, array $values): void
    {
        $this->db->update('control_assessments', $values, 'risk_entity_id = :e AND control_id = :c', ['e' => $entityId, 'c' => $controlId]);
    }

    public function hasAssessments(int $entityId): bool
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM control_assessments WHERE risk_entity_id = :id', ['id' => $entityId]) > 0;
    }

    /** @return list<int> */
    public function templateControlIds(int $templateId): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll('SELECT control_id FROM activity_template_controls WHERE template_id = :t ORDER BY control_id', ['t' => $templateId]),
            'control_id',
        ));
    }

    private static function castControl(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['mandatory'] = (bool) $r['mandatory'];
        $r['verification_required'] = (bool) $r['verification_required'];
        $r['active'] = (bool) $r['active'];
        return $r;
    }

    private static function castAssessment(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['control_id'] = (int) $r['control_id'];
        $r['mandatory'] = (bool) $r['mandatory'];
        $r['verification_required'] = (bool) $r['verification_required'];
        $r['effectiveness_pct'] = $r['effectiveness_pct'] === null ? null : (int) $r['effectiveness_pct'];
        if (array_key_exists('control_active', $r)) {
            $r['control_active'] = (bool) $r['control_active'];
        }
        return $r;
    }
}
