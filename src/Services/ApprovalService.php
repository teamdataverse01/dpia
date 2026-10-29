<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ApprovalDecision as D;
use App\Domain\AssessmentStatus as St;
use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\Rating;
use App\Domain\Role;
use App\Infrastructure\AuditLogger;
use App\Infrastructure\PdoConnection;
use App\Repositories\ApprovalRepository;
use App\Repositories\ConfigRepository;
use App\Repositories\RiskEntityRepository;
use App\Support\Clock;
use App\Support\ConflictException;
use App\Support\ForbiddenException;
use App\Support\ValidationException;

/**
 * Stage 8 — submission, tiered approval routing and time-boxed risk acceptance (PRD 6, 16).
 *
 * Routing comes from scoring_config.approval_routing (default: High and Medium → Privacy Team/DPO;
 * Low / Very Low → Reviewer). Every required role must approve in the
 * current round; any rejection rejects. Accepting the risk instead of approving needs the
 * "approve / accept risk" permission (Privacy Team/DPO or Administrator) and an end date.
 */
final class ApprovalService
{
    public function __construct(
        private readonly PdoConnection $db,
        private readonly AssessmentService $assessments,
        private readonly RiskEntityRepository $entities,
        private readonly ApprovalRepository $approvals,
        private readonly ConfigRepository $configs,
        private readonly StatusTransitioner $status,
        private readonly Authorizer $authz,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<Role> */
    public function requiredRoles(array $entity): array
    {
        if ($entity['residual_rating'] === null) {
            return [];
        }
        return $this->configs->active()->approversFor(Rating::from($entity['residual_rating']));
    }

    /**
     * Approval progress in the current round.
     *
     * @return array{required: list<Role>, approved_roles: list<string>, outstanding: list<Role>, decisions: list<array>}
     */
    public function round(array $entity): array
    {
        $required = $this->requiredRoles($entity);
        $decisions = $entity['submitted_at'] === null ? [] : $this->approvals->since((int) $entity['id'], (string) $entity['submitted_at']);
        $approvedRoles = [];
        foreach ($decisions as $d) {
            if ($d['decision'] === D::Approved->value && $d['approver_role'] !== null) {
                $approvedRoles[] = $d['approver_role'];
            }
        }
        $adminApproved = in_array(Role::Admin->value, $approvedRoles, true);
        $outstanding = $adminApproved ? [] : array_values(array_filter($required, fn (Role $r) => !in_array($r->value, $approvedRoles, true)));
        return ['required' => $required, 'approved_roles' => $approvedRoles, 'outstanding' => $outstanding, 'decisions' => $decisions];
    }

    public function submit(PortalUser $user, int $id): void
    {
        $e = $this->assessments->get($id);
        $this->authz->authorize($user, P::Submit, $e);
        if ($e['status'] !== St::ResidualCalculated->value) {
            throw new ConflictException('Calculate the residual risk (stage 6) before submitting for approval.');
        }
        $this->db->transaction(function () use ($user, $e) {
            $this->status->to($user->id, $e, St::PendingApproval, ['submitted_at' => $this->clock->now()->format('Y-m-d H:i:s.u')]);
        });

        $required = $this->requiredRoles($e);
        $this->notifier->toRoles(
            $required,
            'Approval needed: ' . $e['name'],
            sprintf(
                "\"%s\" has been submitted for approval.\nResidual risk: %s.\nPlease review it here: %s",
                $e['name'],
                Rating::from($e['residual_rating'])->label(),
                $this->notifier->link('/assessments/' . $id . '/review'),
            ),
        );
    }

    public function canDecide(PortalUser $user, array $entity): bool
    {
        return $entity['status'] === St::PendingApproval->value
            && $this->authz->can($user, P::Decide, $entity + ['required_roles' => $this->round($entity)['outstanding']]);
    }

    public function decide(PortalUser $user, int $id, string $decision, string $justification, ?string $acceptedUntil): string
    {
        $e = $this->assessments->get($id);
        if ($e['status'] !== St::PendingApproval->value) {
            throw new ConflictException('This assessment is not waiting for approval.');
        }
        $d = D::tryFrom($decision);
        $errors = [];
        if ($d === null) {
            $errors['decision'] = 'Choose approve, reject or accept the risk.';
        }
        $justification = trim($justification);
        if (mb_strlen($justification) < 5) {
            $errors['justification'] = 'Give a short justification for the decision.';
        }
        $acceptedUntil = $acceptedUntil !== null && trim($acceptedUntil) !== '' ? trim($acceptedUntil) : null;
        if ($d === D::RiskAccepted) {
            if ($acceptedUntil === null || !AssessmentService::isDate($acceptedUntil)) {
                $errors['accepted_until'] = 'Risk acceptance must have an end date (YYYY-MM-DD).';
            } elseif ($acceptedUntil <= $this->clock->today()->format('Y-m-d')) {
                $errors['accepted_until'] = 'The acceptance end date must be in the future.';
            }
        } elseif ($acceptedUntil !== null) {
            $acceptedUntil = null; // only meaningful for risk acceptance
        }
        if ($errors !== []) {
            throw new ValidationException('The decision needs attention.', $errors);
        }

        $round = $this->round($e);
        $actingRole = $this->actingRole($user, $round['outstanding'], $round['required']);
        if ($actingRole === null) {
            throw new ForbiddenException('Your role is not one of the approvers for a ' . Rating::from($e['residual_rating'])->label() . ' residual risk, or your role has already approved.');
        }
        if ($d === D::RiskAccepted && !$this->authz->can($user, P::Approve)) {
            throw new ForbiddenException('Only the Privacy Team / DPO can accept a risk.');
        }

        return $this->db->transaction(function () use ($user, $id, $e, $d, $justification, $acceptedUntil, $actingRole) {
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $approvalId = $this->approvals->create([
                'risk_entity_id' => $id,
                'decision' => $d->value,
                'decided_by' => $user->id,
                'approver_role' => $actingRole,
                'justification' => $justification,
                'accepted_until' => $acceptedUntil,
                'residual_rating_at_decision' => $e['residual_rating'],
                'decided_at' => $now,
            ]);
            $this->audit->record($user->id, 'approvals', $approvalId, 'decision', null, [
                'risk_entity_id' => $id, 'decision' => $d->value, 'approver_role' => $actingRole,
                'justification' => $justification, 'accepted_until' => $acceptedUntil,
            ]);

            $config = $this->configs->active();
            $rating = Rating::from($e['residual_rating']);
            $outcome = 'recorded';
            if ($d === D::Rejected) {
                $this->status->to($user->id, $e, St::Rejected);
                $outcome = St::Rejected->value;
                $this->notifier->toUser($e['owner_portal_user_id'], 'Assessment rejected: ' . $e['name'], "Reason: {$justification}\n" . $this->notifier->link('/assessments/' . $id . '/review'));
            } elseif ($d === D::RiskAccepted) {
                $this->status->to($user->id, $e, St::RiskAccepted, ['next_review_date' => $acceptedUntil]);
                $outcome = St::RiskAccepted->value;
            } else {
                $after = $this->round($this->assessments->get($id));
                if ($after['outstanding'] === []) {
                    $next = $this->clock->today()->modify('+' . $config->reviewIntervalMonths[$rating->value] . ' months')->format('Y-m-d');
                    $this->status->to($user->id, $e, St::Approved, ['next_review_date' => $next]);
                    $outcome = St::Approved->value;
                    $this->notifier->toUser($e['owner_portal_user_id'], 'Assessment approved: ' . $e['name'], "Next review is due on {$next}.\n" . $this->notifier->link('/assessments/' . $id . '/monitoring'));
                }
            }
            return $outcome;
        });
    }

    /**
     * The role the user decides in: an outstanding required role they hold (Administrator acts for all).
     *
     * @param list<Role> $outstanding
     * @param list<Role> $required
     */
    private function actingRole(PortalUser $user, array $outstanding, array $required): ?string
    {
        if ($user->hasRole(Role::Admin)) {
            return Role::Admin->value;
        }
        foreach ($outstanding as $r) {
            if ($user->hasRole($r)) {
                return $r->value;
            }
        }
        return null;
    }
}
