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
    /** PRD section 5 matrix (role-only part). EditOwn is further limited to the user's own records. */
    private const MATRIX = [
        'create' => [R::Requestor, R::Admin],
        'edit_own' => [R::Requestor, R::Vendor, R::Admin],
        'edit_controls' => [R::Vendor, R::BusinessOwner, R::InfoSec, R::PrivacyDpo, R::Reviewer, R::Admin],
        'approve' => [R::RiskApprover, R::Admin],
        'admin_config' => [R::Admin],
        'read_all' => [R::Admin, R::Auditor],
    ];

    /** Roles that work on other people's records and therefore may view them. */
    private const OPERATIONAL_VIEWERS = [R::BusinessOwner, R::InfoSec, R::PrivacyDpo, R::Reviewer, R::RiskApprover];

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
            P::ViewDashboard => $this->hasAny($user, [R::PrivacyDpo, R::RiskApprover, R::Auditor]),
            P::ConfirmNegligible => $this->hasAny($user, [R::Reviewer, R::RiskApprover]),
            P::VerifyEvidence => $this->hasAny($user, [R::Reviewer]),
            P::SaveTemplate => $this->hasAny($user, [R::PrivacyDpo]),
            P::Decide => $this->decide($user, $resource),
            P::ManageRemediation => $this->editControls($user, $resource) || $this->editOwn($user, $resource),
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
     * Narrowing for list queries: null = no restriction, otherwise the user may only list rows they own
     * (and, for department-scoped business owners, rows in their department).
     *
     * @return array{owner_only: bool, department_ids: list<int>|null}
     */
    public function listScope(PortalUser $user): array
    {
        if ($this->hasAny($user, [R::Admin, R::Auditor, R::InfoSec, R::PrivacyDpo, R::Reviewer, R::RiskApprover])) {
            return ['owner_only' => false, 'department_ids' => null];
        }
        if ($user->hasRole(R::BusinessOwner)) {
            $dept = $user->roleDepartments[R::BusinessOwner->value] ?? null;
            return ['owner_only' => false, 'department_ids' => $dept === null ? null : [(int) $dept]];
        }
        return ['owner_only' => true, 'department_ids' => null];
    }

    private function editOwn(PortalUser $user, ?array $resource): bool
    {
        if (!$this->hasAny($user, [R::Requestor, R::Vendor])) {
            return false;
        }
        if ($resource === null) {
            return false;
        }
        if ($user->hasRole(R::Requestor) && ($resource['owner_portal_user_id'] ?? null) === $user->id) {
            return true;
        }
        // Vendor role: only its own vendor record (PRD 5 notes).
        return $user->hasRole(R::Vendor)
            && ($resource['entity_type'] ?? null) === 'vendor'
            && ($resource['vendor_portal_user_id'] ?? null) === $user->id;
    }

    private function editControls(PortalUser $user, ?array $resource): bool
    {
        if ($this->hasAny($user, [R::InfoSec, R::PrivacyDpo, R::Reviewer])) {
            return true;
        }
        if ($user->hasRole(R::BusinessOwner) && $this->inBusinessOwnerScope($user, $resource)) {
            return true;
        }
        return $user->hasRole(R::Vendor)
            && $resource !== null
            && ($resource['entity_type'] ?? null) === 'vendor'
            && ($resource['vendor_portal_user_id'] ?? null) === $user->id;
    }

    private function view(PortalUser $user, ?array $resource): bool
    {
        if ($this->hasAny($user, [R::Auditor, R::InfoSec, R::PrivacyDpo, R::Reviewer, R::RiskApprover])) {
            return true;
        }
        if ($user->hasRole(R::BusinessOwner) && $this->inBusinessOwnerScope($user, $resource)) {
            return true;
        }
        return $this->isOwner($user, $resource);
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

    private function inBusinessOwnerScope(PortalUser $user, ?array $resource): bool
    {
        $dept = $user->roleDepartments[R::BusinessOwner->value] ?? null;
        if ($dept === null) {
            return true;
        }
        return $resource !== null && isset($resource['department_id']) && (int) $resource['department_id'] === (int) $dept;
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
