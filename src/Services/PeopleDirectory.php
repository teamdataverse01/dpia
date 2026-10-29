<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AuthProvider;
use App\Domain\Role;
use App\Repositories\ReferenceRepository;

/**
 * Everyone who holds a role in this module, with display names from the portal (AuthProvider).
 * Used for "who owns this action" pickers so people choose a person, not type an ID.
 */
final class PeopleDirectory
{
    /** @var array<string,array{id:string,name:string,roles:list<Role>,label:string}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ReferenceRepository $refs,
        private readonly AuthProvider $auth,
    ) {
    }

    /** @return array<string,array{id:string,name:string,roles:list<Role>,label:string}> keyed by portal user id, sorted by name */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $people = [];
        foreach ($this->refs->allUserRoles() as $r) {
            $id = (string) $r['portal_user_id'];
            $role = Role::tryFrom((string) $r['role']);
            if (!isset($people[$id])) {
                $people[$id] = ['id' => $id, 'name' => $this->auth->findUser($id)?->displayName ?? $id, 'roles' => []];
            }
            if ($role !== null) {
                $people[$id]['roles'][] = $role;
            }
        }
        foreach ($people as &$p) {
            $p['label'] = $p['name'] . ' — ' . implode(', ', array_map(fn (Role $r) => $r->label(), $p['roles']));
        }
        unset($p);
        uasort($people, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $this->cache = $people;
    }

    /** Display name for a portal user id (falls back to the id). */
    public function name(?string $id): string
    {
        if ($id === null || $id === '') {
            return '—';
        }
        return $this->all()[$id]['name'] ?? ($this->auth->findUser($id)?->displayName ?? $id);
    }

    /** First person holding $role (used to pre-select a sensible owner). */
    public function firstWithRole(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }
        foreach ($this->all() as $p) {
            foreach ($p['roles'] as $r) {
                if ($r->value === $role) {
                    return $p['id'];
                }
            }
        }
        return null;
    }
}
