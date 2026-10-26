<?php

declare(strict_types=1);

namespace App\Domain;

enum AssessmentStatus: string
{
    case Draft = 'draft';
    case Scored = 'scored';
    case ControlsInProgress = 'controls_in_progress';
    case ResidualCalculated = 'residual_calculated';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RiskAccepted = 'risk_accepted';
    case Monitoring = 'monitoring';
    case ReassessmentDue = 'reassessment_due';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scored => 'Scored',
            self::ControlsInProgress => 'Controls in progress',
            self::ResidualCalculated => 'Residual risk calculated',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::RiskAccepted => 'Risk accepted',
            self::Monitoring => 'Monitoring',
            self::ReassessmentDue => 'Reassessment due',
        };
    }

    /** While in these statuses the factors, controls and remediation plan can still be edited. */
    public function isEditable(): bool
    {
        return in_array($this, [
            self::Draft, self::Scored, self::ControlsInProgress, self::ResidualCalculated,
            self::Rejected, self::ReassessmentDue,
        ], true);
    }

    /** Decided records (approved / accepted / being monitored). */
    public function isDecided(): bool
    {
        return in_array($this, [self::Approved, self::RiskAccepted, self::Monitoring, self::ReassessmentDue], true);
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Scored],
            self::Scored => [self::ControlsInProgress, self::ResidualCalculated],
            self::ControlsInProgress => [self::ResidualCalculated, self::Scored],
            self::ResidualCalculated => [self::PendingApproval, self::ControlsInProgress, self::Scored],
            self::PendingApproval => [self::Approved, self::Rejected, self::RiskAccepted],
            self::Approved, self::RiskAccepted => [self::Monitoring, self::ReassessmentDue],
            self::Rejected => [self::Scored, self::ControlsInProgress, self::ResidualCalculated],
            self::Monitoring => [self::ReassessmentDue],
            self::ReassessmentDue => [self::Scored, self::ControlsInProgress, self::ResidualCalculated],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return $next === $this || in_array($next, $this->allowedNext(), true);
    }
}
