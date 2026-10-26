<?php

declare(strict_types=1);

namespace App\Domain;

/** A user as known to the CU portal, plus the module roles mapped to them in user_roles. */
final class PortalUser
{
    /**
     * @param list<Role> $roles
     * @param array<string,int|null> $roleDepartments role value => department id (nullable)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly ?string $email = null,
        public readonly array $roles = [],
        public readonly array $roleDepartments = [],
    ) {
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** @param list<Role> $roles */
    public function withRoles(array $roles, array $roleDepartments = []): self
    {
        return new self($this->id, $this->displayName, $this->email, $roles, $roleDepartments);
    }

    public function roleLabels(): string
    {
        return implode(', ', array_map(fn (Role $r) => $r->label(), $this->roles)) ?: 'No module role';
    }
}
