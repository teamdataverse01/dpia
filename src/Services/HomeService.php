<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AssessmentStatus as St;
use App\Domain\PortalUser;
use App\Repositories\DashboardRepository;
use App\Repositories\RiskEntityRepository;

/** What each profile's home page shows: the work waiting for that person (DECISIONS D-36). */
final class HomeService
{
    public function __construct(
        private readonly RiskEntityRepository $entities,
        private readonly DashboardRepository $dashboard,
        private readonly ApprovalService $approvals,
        private readonly RemediationService $remediation,
        private readonly ControlService $controls,
    ) {
    }

    /** Assessments waiting for this user's decision (routing decides whose). */
    public function decisionsWaiting(PortalUser $user): array
    {
        $rows = $this->entities->list(['status' => St::PendingApproval->value], ['owner_only' => false]);
        return array_values(array_filter($rows, fn (array $e) => $this->approvals->canDecide($user, $e)));
    }

    /** Evidence that must be verified before its control can count. */
    public function evidenceToVerify(): array
    {
        return $this->dashboard->evidenceToVerify();
    }

    /**
     * The next thing to do on one record, in plain words.
     *
     * @return array{label: string, step: string, needs_you: bool}
     */
    public static function nextStep(array $e): array
    {
        return match (St::from($e['status'])) {
            St::Draft => ['label' => 'Answer the risk questions', 'step' => 'factors', 'needs_you' => true],
            St::Scored => ['label' => 'Review the recommended controls', 'step' => 'recommended', 'needs_you' => true],
            St::ControlsInProgress => ['label' => 'Record controls and evidence', 'step' => 'controls', 'needs_you' => true],
            St::ResidualCalculated => ['label' => 'Submit for approval', 'step' => 'review', 'needs_you' => true],
            St::Rejected => ['label' => 'Rejected: read why, fix and resubmit', 'step' => 'review', 'needs_you' => true],
            St::ReassessmentDue => ['label' => 'Reassess: the review date has arrived', 'step' => 'monitoring', 'needs_you' => true],
            St::PendingApproval => ['label' => 'Waiting for approval', 'step' => 'review', 'needs_you' => false],
            St::Approved, St::RiskAccepted, St::Monitoring => ['label' => 'Done until the next review', 'step' => 'monitoring', 'needs_you' => false],
        };
    }

    /** A CU Activity Owner's records with their next step, and the fixes assigned to them. */
    public function ownerWork(PortalUser $user): array
    {
        $records = array_map(
            fn (array $e) => $e + ['next' => self::nextStep($e)],
            $this->entities->list(['owner' => $user->id], ['owner_only' => false]),
        );
        usort($records, fn ($a, $b) => [!$a['next']['needs_you'], $a['name']] <=> [!$b['next']['needs_you'], $b['name']]);
        return [
            'records' => $records,
            'actions' => $this->remediation->list($user, ['owner' => $user->id, 'status' => 'open_all']),
        ];
    }

    /** A vendor's own record(s): which safeguards CU still needs proof of, and which are done. */
    public function vendorRequests(PortalUser $user): array
    {
        $out = [];
        $rows = $this->entities->list(['entity_type' => 'vendor'], ['owner_only' => true, 'or_department_ids' => [], 'user_id' => $user->id]);
        foreach ($rows as $e) {
            if ($e['vendor_portal_user_id'] !== $user->id) {
                continue;
            }
            $todo = [];
            $done = [];
            foreach ($this->controls->overview($e) as $row) {
                if (!$row['counts_for_clearance']) {
                    continue;
                }
                if ($row['unmet'] === []) {
                    $done[] = $row;
                } else {
                    $todo[] = $row;
                }
            }
            $out[] = ['entity' => $e, 'todo' => $todo, 'done' => $done];
        }
        return $out;
    }
}
