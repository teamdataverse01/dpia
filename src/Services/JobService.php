<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\AssessmentStatus as St;
use App\Infrastructure\AuditLogger;
use App\Infrastructure\PdoConnection;
use App\Repositories\DashboardRepository;
use App\Repositories\RiskEntityRepository;
use App\Support\Clock;

/**
 * Scheduled jobs (run daily from cron: `php bin/console jobs:daily`).
 *  - decided assessments enter monitoring; overdue reviews / expired acceptances become reassessment_due (PRD 6 stage 9)
 *  - reminders through the Mailer interface (PRD 16 milestone 8)
 *  - dashboard risk_snapshots (PRD 11.4 trend)
 */
final class JobService
{
    public const ACTOR = 'system';

    public function __construct(
        private readonly PdoConnection $db,
        private readonly RiskEntityRepository $entities,
        private readonly DashboardRepository $dashboard,
        private readonly StatusTransitioner $status,
        private readonly Notifier $notifier,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,int> counters for the log */
    public function daily(): array
    {
        $today = $this->clock->today()->format('Y-m-d');
        $stats = ['to_monitoring' => 0, 'to_reassessment' => 0, 'reminders' => 0, 'snapshot_rows' => 0];

        $this->db->transaction(function () use ($today, &$stats) {
            // 1. approved / risk_accepted → monitoring
            foreach ($this->db->fetchAll("SELECT id FROM risk_entities WHERE status IN ('approved', 'risk_accepted')") as $r) {
                $e = $this->entities->find((int) $r['id']);
                $this->status->to(self::ACTOR, $e, St::Monitoring);
                $stats['to_monitoring']++;
            }
            // 2. review date reached (risk acceptances use accepted_until as their review date) → reassessment_due
            foreach ($this->db->fetchAll("SELECT id FROM risk_entities WHERE status = 'monitoring' AND next_review_date IS NOT NULL AND next_review_date <= :t", ['t' => $today]) as $r) {
                $e = $this->entities->find((int) $r['id']);
                $this->status->to(self::ACTOR, $e, St::ReassessmentDue);
                $stats['to_reassessment']++;
                $stats['reminders'] += (int) $this->notifier->toUser(
                    $e['owner_portal_user_id'],
                    'Reassessment due: ' . $e['name'],
                    "The review date for \"{$e['name']}\" has been reached. Please reassess it: " . $this->notifier->link('/assessments/' . $e['id'] . '/monitoring'),
                );
            }
        });

        // 3. reminders (outside the transaction: mail failures must not roll back state changes)
        foreach ($this->db->fetchAll(
            "SELECT r.id, r.gap_description, r.target_date, r.owner_portal_user_id, e.name
               FROM remediation_actions r JOIN risk_entities e ON e.id = r.risk_entity_id
              WHERE r.status IN ('open', 'in_progress') AND r.target_date < :t",
            ['t' => $today],
        ) as $a) {
            $stats['reminders'] += (int) $this->notifier->toUser(
                $a['owner_portal_user_id'],
                'Overdue remediation action: ' . $a['name'],
                "This action was due on {$a['target_date']}:\n{$a['gap_description']}\n" . $this->notifier->link('/remediation?status=overdue'),
            );
        }
        $in30 = $this->clock->today()->modify('+30 days')->format('Y-m-d');
        foreach ($this->db->fetchAll(
            "SELECT id, name, owner_portal_user_id, next_review_date FROM risk_entities
              WHERE status IN ('risk_accepted', 'monitoring') AND next_review_date BETWEEN :t AND :u",
            ['t' => $today, 'u' => $in30],
        ) as $e) {
            $msg = "\"{$e['name']}\" is due for review (or its risk acceptance ends) on {$e['next_review_date']}. " . $this->notifier->link('/assessments/' . $e['id'] . '/monitoring');
            $stats['reminders'] += (int) $this->notifier->toUser($e['owner_portal_user_id'], 'Review coming up: ' . $e['name'], $msg);
        }
        foreach ($this->db->fetchAll(
            "SELECT e.id, e.name, e.owner_portal_user_id, c.title, ca.expiry_date
               FROM control_assessments ca JOIN controls c ON c.id = ca.control_id JOIN risk_entities e ON e.id = ca.risk_entity_id
              WHERE e.status <> 'rejected' AND ca.evidence_path IS NOT NULL AND ca.expiry_date BETWEEN :t AND :u",
            ['t' => $today, 'u' => $in30],
        ) as $x) {
            $stats['reminders'] += (int) $this->notifier->toUser(
                $x['owner_portal_user_id'],
                'Evidence expiring: ' . $x['name'],
                "Evidence for \"{$x['title']}\" expires on {$x['expiry_date']}. Upload a current version so the control stays cleared: " . $this->notifier->link('/assessments/' . $x['id'] . '/controls'),
            );
        }

        // 4. dashboard snapshot
        $stats['snapshot_rows'] = $this->snapshot();
        return $stats;
    }

    public function snapshot(): int
    {
        return $this->db->transaction(fn () => $this->dashboard->writeSnapshot($this->clock->today()->format('Y-m-d')));
    }
}
