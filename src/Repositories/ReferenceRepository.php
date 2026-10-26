<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Role;
use App\Infrastructure\PdoConnection;

/** Departments, factor options and user-role mappings. */
final class ReferenceRepository
{
    /** @var array<string,list<array<string,mixed>>>|null */
    private ?array $optionsCache = null;

    public function __construct(private readonly PdoConnection $db)
    {
    }

    /** @return list<array{id:int,name:string}> */
    public function departments(): array
    {
        return array_map(
            fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
            $this->db->fetchAll('SELECT id, name FROM departments ORDER BY name'),
        );
    }

    public function department(int $id): ?array
    {
        return $this->db->fetchOne('SELECT id, name FROM departments WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,list<array<string,mixed>>> factor => options ordered by sort_order */
    public function factorOptions(): array
    {
        if ($this->optionsCache === null) {
            $out = [];
            foreach ($this->db->fetchAll('SELECT id, factor, option_key, label, help_text, score, sort_order FROM factor_options ORDER BY factor, sort_order, id') as $r) {
                $r['id'] = (int) $r['id'];
                $r['score'] = (int) $r['score'];
                $r['sort_order'] = (int) $r['sort_order'];
                $out[$r['factor']][] = $r;
            }
            $this->optionsCache = $out;
        }
        return $this->optionsCache;
    }

    public function factorOption(string $factor, string $key): ?array
    {
        foreach ($this->factorOptions()[$factor] ?? [] as $o) {
            if ($o['option_key'] === $key) {
                return $o;
            }
        }
        return null;
    }

    public function factorOptionById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM factor_options WHERE id = :id', ['id' => $id]);
    }

    public function updateFactorOption(int $id, string $label, ?string $help, int $score, int $sortOrder): void
    {
        $this->db->update('factor_options', [
            'label' => $label, 'help_text' => $help, 'score' => $score, 'sort_order' => $sortOrder,
        ], 'id = :id', ['id' => $id]);
        $this->optionsCache = null;
    }

    public function createFactorOption(string $factor, string $key, string $label, ?string $help, int $score, int $sortOrder): int
    {
        $this->optionsCache = null;
        return $this->db->insert('factor_options', [
            'factor' => $factor, 'option_key' => $key, 'label' => $label, 'help_text' => $help,
            'score' => $score, 'sort_order' => $sortOrder,
        ]);
    }

    /** @return array{roles: list<Role>, departments: array<string,int|null>} */
    public function rolesFor(string $portalUserId): array
    {
        $roles = [];
        $depts = [];
        foreach ($this->db->fetchAll('SELECT role, department_id FROM user_roles WHERE portal_user_id = :u ORDER BY role', ['u' => $portalUserId]) as $r) {
            $role = Role::tryFrom($r['role']);
            if ($role !== null) {
                $roles[] = $role;
                $depts[$role->value] = $r['department_id'] === null ? null : (int) $r['department_id'];
            }
        }
        return ['roles' => $roles, 'departments' => $depts];
    }

    /** @return list<string> portal user ids holding any of the roles */
    public function usersWithRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }
        $params = [];
        $ph = [];
        foreach (array_values($roles) as $i => $r) {
            $ph[] = ':r' . $i;
            $params['r' . $i] = $r instanceof Role ? $r->value : (string) $r;
        }
        return array_column(
            $this->db->fetchAll('SELECT DISTINCT portal_user_id FROM user_roles WHERE role IN (' . implode(', ', $ph) . ') ORDER BY portal_user_id', $params),
            'portal_user_id',
        );
    }

    /** @return list<array<string,mixed>> */
    public function allUserRoles(): array
    {
        return $this->db->fetchAll(
            'SELECT ur.id, ur.portal_user_id, ur.role, ur.department_id, d.name AS department_name
               FROM user_roles ur LEFT JOIN departments d ON d.id = ur.department_id
              ORDER BY ur.portal_user_id, ur.role',
        );
    }

    public function findUserRole(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM user_roles WHERE id = :id', ['id' => $id]);
    }

    public function addUserRole(string $portalUserId, Role $role, ?int $departmentId): int
    {
        return $this->db->insert('user_roles', [
            'portal_user_id' => $portalUserId, 'role' => $role->value, 'department_id' => $departmentId,
        ]);
    }

    public function removeUserRole(int $id): void
    {
        $this->db->execute('DELETE FROM user_roles WHERE id = :id', ['id' => $id]);
    }
}
