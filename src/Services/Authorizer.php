<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\Role as R;
use App\Support\ForbiddenException;

/**
 * The single server-side permission check used by every controller (PRD 5).
 *
 * $resource, when given, is a risk entity row (or any array) with these optional keys:
 *   owner_portal_user_id, entity_type, department_id, vendor_portal_user_id,
 *   required_roles (list<Role>, for Permission::Decide).
 */
final class Authorizer
{
    /**
     * Who may do what (four profiles, DECISIONS D-35). EditOwn is further limited to the user's own records.
     * Admin is the single DPO / Administrator profile and may do everything.
     */
    private const MATRIX = [
        'create' => [R::BusinessOwner, R::Admin],
        'edit_own' => [R::BusinessOwner, R::Vendor, R::Admin],
        'edit_controls' => [R::Vendor, R::BusinessOwner, R::Reviewer, R::Admin],
        'approve' => [R::Admin],
        'admin_config' => [R::Admin],
        'read_all' => [R::Admin],
    ];

    public function can(PortalUser $user, P $permission, ?array $resource = null): bool
    {
        if ($user->hasRole(R::Admin)) {
            return true;
        }

        return match ($permission) {
            P::Create, P::AdminConfig, P::ReadAll, P::Approve => $this->hasAny($user, self::MATRIX[$permission->value]),
            P::EditOwn => $this->editOwn($user, $resource),
            P::EditControls => $this->editControls($user, $resource),
            P::View => $this->view($user, $resource),
            P::ViewDashboard, P::ConfirmNegligible, P::VerifyEvidence => $this->hasAny($user, [R::Reviewer]),
            P::SaveTemplate => false, // DPO / Administrator only (handled above)
            P::Decide => $this->decide($user, $resource),
            // being assigned one fix lets you work on it, not re-plan the record's remediation
            P::ManageRemediation => $user->hasRole(R::Reviewer) || $this->inBusinessOwnerScope($user, $resource) || $this->editOwn($user, $resource),
            P::Submit => $this->editOwn($user, $resource),
        };
    }

    /** @throws ForbiddenException */
    public function authorize(PortalUser $user, P $permission, ?array $resource = null): void
    {
        if (!$this->can($user, $permission, $resource)) {
            throw new ForbiddenException();
        }
    }

    public function isOwner(PortalUser $user, ?array $resource): bool
    {
        if ($resource === null) {
            return false;
        }
        return ($resource['owner_portal_user_id'] ?? null) === $user->id
            || (($resource['entity_type'] ?? null) === 'vendor' && ($resource['vendor_portal_user_id'] ?? null) === $user->id);
    }

    /**
     * Narrowing for list queries. owner_only limits rows to the user's own records and the ones they have
     * been assigned a fix on; or_department_ids adds a Business Owner's department to that.
     *
     * @return array{owner_only: bool, or_department_ids: list<int>}
     */
    public function listScope(PortalUser $user): array
    {
        if ($this->hasAny($user, [R::Admin, R::Reviewer])) {
            return ['owner_only' => false, 'or_department_ids' => []];
        }
        $dept = $user->hasRole(R::BusinessOwner) ? ($user->roleDepartments[R::BusinessOwner->value] ?? null) : null;
        return ['owner_only' => true, 'or_department_ids' => $dept === null ? [] : [(int) $dept]];
    }

    private function editOwn(PortalUser $user, ?array $resource): bool
    {
        if ($resource === null) {
            return false;
        }
        if ($user->hasRole(R::BusinessOwner) && ($resource['owner_portal_user_id'] ?? null) === $user->id) {
            return true;
        }
        // Vendor role: only its own vendor record (PRD 5 notes).
        return $user->hasRole(R::Vendor)
            && ($resource['entity_type'] ?? null) === 'vendor'
            && ($resource['vendor_portal_user_id'] ?? null) === $user->id;
    }

    private function editControls(PortalUser $user, ?array $resource): bool
    {
        return $user->hasRole(R::Reviewer)
            || $this->inBusinessOwnerScope($user, $resource)
            || $this->isAssigned($user, $resource)
            || $this->editOwn($user, $resource);
    }

    private function view(PortalUser $user, ?array $resource): bool
    {
        return $user->hasRole(R::Reviewer)
            || $this->inBusinessOwnerScope($user, $resource)
            || $this->isAssigned($user, $resource)
            || $this->isOwner($user, $resource);
    }

    private function decide(PortalUser $user, ?array $resource): bool
    {
        $required = $resource['required_roles'] ?? [];
        foreach ($required as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }
        return false;
    }

    /** A Business Owner works on the records they own and, when mapped to a department, that department's records. */
    private function inBusinessOwnerScope(PortalUser $user, ?array $resource): bool
    {
        if ($resource === null || !$user->hasRole(R::BusinessOwner)) {
            return false;
        }
        if (($resource['owner_portal_user_id'] ?? null) === $user->id) {
            return true;
        }
        $dept = $user->roleDepartments[R::BusinessOwner->value] ?? null;
        return $dept !== null && isset($resource['department_id']) && (int) $resource['department_id'] === (int) $dept;
    }

    /** The user has been assigned a remediation action on this record, so they must be able to open it and record the fix. */
    private function isAssigned(PortalUser $user, ?array $resource): bool
    {
        return in_array($user->id, explode(',', (string) ($resource['action_owner_ids'] ?? '')), true);
    }

    /** @param list<R> $roles */
    private function hasAny(PortalUser $user, array $roles): bool
    {
        foreach ($roles as $r) {
            if ($user->hasRole($r)) {
                return true;
            }
        }
        return false;
    }
}
