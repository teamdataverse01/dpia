<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Role;
use App\Engine\ScoringConfig;
use App\Support\ConflictException;
use App\Support\ForbiddenException;
use App\Support\ValidationException;

/** Approval routing, risk acceptance, monitoring, residual, audit trail and config versioning. */
final class WorkflowTest extends IntegrationTestCase
{
    public function testFastPathDpiaReachesLowWithTechnologyAndProcessCleared(): void
    {
        $e = $this->entityByName('Processing Student Applications');
        self::assertSame('high', $e['inherent_rating']);
        self::assertSame('low', $e['residual_rating']);
        self::assertSame('1.35', (string) $e['residual_score']); // PRD 9.5 case 1
        self::assertSame('65.00', (string) $e['risk_reduced_pct']);
        $x = $this->app()->assessments()->residualExplanation($e);
        self::assertSame('high_two_plus_with_technology', $x['rule_applied']);
        self::assertNotEmpty($x['categories']['policy']['why_not']);
    }

    public function testMediumRoutesToPrivacyTeam(): void
    {
        $this->inRollback(function () {
            $m365 = $this->entityByName('Microsoft 365'); // pending, residual Medium
            self::assertSame('pending_approval', $m365['status']);
            try {
                $this->app()->approvals()->decide($this->user('dev.reviewer'), (int) $m365['id'], 'approved', 'Looks fine to me.', null);
                self::fail('reviewer is not an approver for Medium');
            } catch (ForbiddenException) {
            }
            $out = $this->app()->approvals()->decide($this->user('dev.privacy_dpo'), (int) $m365['id'], 'approved', 'Controls planned and acceptable.', null);
            self::assertSame('approved', $out);
            $after = $this->app()->entities()->find((int) $m365['id']);
            self::assertSame('2027-10-18', $after['next_review_date']); // Medium → 12 months
        });
    }

    public function testHighIsApprovedByThePrivacyTeamNotTheReviewer(): void
    {
        $this->inRollback(function () {
            $cctv = $this->entityByName('Campus CCTV with facial recognition'); // High, controls in progress
            $id = (int) $cctv['id'];
            $this->app()->assessments()->calculateResidual($this->user('dev.requestor'), $id);
            $this->app()->approvals()->submit($this->user('dev.requestor'), $id);
            self::assertSame([Role::PrivacyDpo], $this->app()->approvals()->round($this->app()->entities()->find($id))['required']);

            try {
                $this->app()->approvals()->decide($this->user('dev.reviewer'), $id, 'approved', 'Looks fine.', null);
                self::fail('a Reviewer only approves Low-risk items');
            } catch (ForbiddenException) {
            }

            self::assertSame('approved', $this->app()->approvals()->decide($this->user('dev.privacy_dpo'), $id, 'approved', 'Agreed, with the plan.', null));
            self::assertSame('2027-04-18', $this->app()->entities()->find($id)['next_review_date']); // High → 6 months
        });
    }

    public function testRiskAcceptanceIsTimeBoxedAndOnlyForThePrivacyTeam(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Microsoft 365')['id'];
            try {
                $this->app()->approvals()->decide($this->user('dev.reviewer'), $id, 'risk_accepted', 'Accept it.', '2027-01-31');
                self::fail('a Reviewer cannot accept risk');
            } catch (ForbiddenException) {
            }
            try {
                $this->app()->approvals()->decide($this->user('dev.privacy_dpo'), $id, 'risk_accepted', 'Accept it.', null);
                self::fail('end date required');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('accepted_until', $e->fields);
            }
            self::assertSame('risk_accepted', $this->app()->approvals()->decide($this->user('dev.privacy_dpo'), $id, 'risk_accepted', 'Accept until migration.', '2027-01-31'));
            self::assertSame('2027-01-31', $this->app()->entities()->find($id)['next_review_date']);
        });
    }

    private function controlId(string $title): int
    {
        return (int) $this->app()->db()->fetchValue('SELECT id FROM controls WHERE title = :t', ['t' => $title]);
    }

    public function testActionClosesItselfWhenItsControlIsFixedAndVerified(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Campus CCTV with facial recognition')['id'];
            $cid = $this->controlId('Encryption at rest and in transit');
            $actionId = $this->app()->remediation()->create($this->user('dev.privacy_dpo'), [
                'risk_entity_id' => $id, 'control_id' => $cid, 'gap_description' => 'Encrypt the CCTV archive.',
                'owner_portal_user_id' => 'dev.business_owner', 'priority' => 'high', 'target_date' => '2026-11-30',
            ]);

            $tmp = tempnam(sys_get_temp_dir(), 'ev');
            file_put_contents($tmp, "Encryption settings export\n");
            $this->app()->controlService()->record($this->user('dev.business_owner'), $id, $cid, [
                'status' => 'implemented', 'effectiveness_pct' => '85', 'expiry_date' => '2027-10-01',
            ], ['tmp_name' => $tmp, 'name' => 'encryption.txt', 'error' => 0]);
            self::assertSame('open', $this->app()->remediationRepo()->find($actionId)['status'], 'still needs Reviewer verification');

            $this->app()->controlService()->verify($this->user('dev.reviewer'), $id, $cid);
            $a = $this->app()->remediationRepo()->find($actionId);
            self::assertSame('completed', $a['status']);
            self::assertNotNull($a['completed_at']);
            $audit = $this->app()->db()->fetchOne("SELECT * FROM audit_log WHERE entity_table = 'remediation_actions' AND entity_id = :id AND action = 'auto_completed'", ['id' => $actionId]);
            self::assertSame('system', $audit['actor_portal_user_id']);
            self::assertStringContainsString('"triggered_by":"dev.reviewer"', $audit['new_value']);
        });
    }

    public function testRemindOwner(): void
    {
        $this->inRollback(function () {
            $overdue = $this->app()->remediation()->list($this->user('dev.privacy_dpo'), ['status' => 'overdue'])[0];
            self::assertTrue($this->app()->remediation()->remind($this->user('dev.privacy_dpo'), (int) $overdue['id']));
            self::assertNotNull($this->app()->db()->fetchOne("SELECT id FROM audit_log WHERE entity_table = 'remediation_actions' AND entity_id = :id AND action = 'reminder_sent'", ['id' => (int) $overdue['id']]));

            try {
                $this->app()->remediation()->remind($this->user('dev.requestor'), (int) $overdue['id']); // not Ada's record
                self::fail('only people who manage the record may chase its actions');
            } catch (ForbiddenException) {
            }

            $done = $this->app()->remediation()->list($this->user('dev.privacy_dpo'), ['status' => 'completed'])[0];
            $this->expectException(\App\Support\ConflictException::class);
            $this->app()->remediation()->remind($this->user('dev.privacy_dpo'), (int) $done['id']);
        });
    }

    public function testRejectionAllowsRework(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Microsoft 365')['id'];
            $this->app()->approvals()->decide($this->user('dev.privacy_dpo'), $id, 'rejected', 'Need a DPA first.', null);
            self::assertSame('rejected', $this->app()->entities()->find($id)['status']);
            $f = $this->app()->entities()->factorInputs($id);
            $this->app()->assessments()->saveFactors($this->user('dev.requestor'), $id, $f);
            self::assertSame('scored', $this->app()->entities()->find($id)['status']);
        });
    }

    public function testPendingApprovalIsLocked(): void
    {
        $id = (int) $this->entityByName('Microsoft 365')['id'];
        $this->expectException(ConflictException::class);
        $this->app()->assessments()->saveFactors($this->user('dev.requestor'), $id, $this->app()->entities()->factorInputs($id));
    }

    public function testVeryLowOnlyFromComputedLow(): void
    {
        self::assertSame('very_low', $this->entityByName('Exam results publication')['residual_rating']);
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Fee payment processing')['id']; // Medium, nothing cleared
            $this->expectException(ConflictException::class);
            $this->app()->assessments()->confirmNegligible($this->user('dev.reviewer'), $id, 'It is fine, honestly, nothing to see.');
        });
    }

    public function testDailyJobMovesDueItemsToReassessmentAndWritesSnapshot(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Student wellbeing survey')['id'];
            self::assertSame('monitoring', $this->app()->entities()->find($id)['status']);
            $this->app()->entities()->update($id, ['next_review_date' => '2026-10-17']);
            $stats = $this->app()->jobs()->daily();
            self::assertSame(1, $stats['to_reassessment']);
            self::assertSame('reassessment_due', $this->app()->entities()->find($id)['status']);
            self::assertGreaterThan(0, (int) $this->app()->db()->fetchValue("SELECT COUNT(*) FROM risk_snapshots WHERE snapshot_date = '2026-10-18'"));

            $this->app()->assessments()->startReassessment($this->user('dev.requestor2'), $id); // the survey's owner
            self::assertSame('scored', $this->app()->entities()->find($id)['status']);
        });
    }

    public function testEveryStateChangeIsAuditedInTheSameTransaction(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Turnitin')['id'];
            $before = (int) $this->app()->db()->fetchValue('SELECT COUNT(*) FROM audit_log');
            $this->app()->assessments()->calculateResidual($this->user('dev.infosec'), $id);
            $rows = $this->app()->db()->fetchAll('SELECT * FROM audit_log ORDER BY id DESC LIMIT 5');
            self::assertGreaterThan($before, (int) $this->app()->db()->fetchValue('SELECT COUNT(*) FROM audit_log'));
            $actions = array_column($rows, 'action');
            self::assertContains('status_change', $actions);
            self::assertSame('dev.infosec', $rows[0]['actor_portal_user_id']);
        });
    }

    public function testAuditLogCannotBeModified(): void
    {
        $db = $this->app()->db();
        foreach (['UPDATE audit_log SET action = \'x\' WHERE id = 1', 'DELETE FROM audit_log WHERE id = 1'] as $sql) {
            try {
                $db->execute($sql);
                self::fail('audit_log must be append-only');
            } catch (\PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function testConfigIsVersionedAndValidated(): void
    {
        $this->inRollback(function () {
            $bad = ScoringConfig::defaults();
            $bad['weights']['data_volume'] = 20;
            try {
                $this->app()->admin()->saveConfig($this->user('dev.admin'), $bad, null);
                self::fail('weights totalling 105 must be rejected');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('weights', $e->fields);
            }
            try {
                $this->app()->admin()->saveConfig($this->user('dev.privacy_dpo'), ScoringConfig::defaults(), null);
                self::fail('only admins change config');
            } catch (ForbiddenException) {
            }

            $good = ScoringConfig::defaults();
            $good['weights']['data_volume'] = 0;
            $good['weights']['cross_border_transfer'] = 30;
            $v = $this->app()->admin()->saveConfig($this->user('dev.admin'), $good, 'move weight');
            self::assertSame(2, $v);
            self::assertSame(1, (int) $this->app()->db()->fetchValue('SELECT COUNT(*) FROM scoring_config WHERE active = TRUE'));

            $id = (int) $this->entityByName('Fee payment processing')['id'];
            self::assertSame(1, (int) $this->app()->entities()->find($id)['scoring_framework_version'], 'keeps its version until rescored');
            $this->app()->assessments()->score($this->user('dev.requestor'), $id);
            self::assertSame(2, (int) $this->app()->entities()->find($id)['scoring_framework_version']);
        });
    }

    public function testEvidenceUploadsAreAllowListed(): void
    {
        $this->inRollback(function () {
            $e = $this->entityByName('Campus CCTV with facial recognition');
            $cid = (int) $this->app()->controls()->assessmentsFor((int) $e['id'])[0]['control_id'];
            $tmp = tempnam(sys_get_temp_dir(), 'ev');
            file_put_contents($tmp, '<?php echo "x";');
            foreach (['shell.php' => 'File type not allowed.', 'fake.pdf' => 'File content does not match its type.'] as $name => $msg) {
                try {
                    $this->app()->controlService()->record($this->user('dev.infosec'), (int) $e['id'], $cid, [], ['tmp_name' => $tmp, 'name' => $name, 'error' => 0]);
                    self::fail("{$name} must be rejected");
                } catch (ValidationException $ex) {
                    self::assertSame($msg, $ex->fields['evidence']);
                }
            }
            @unlink($tmp);
        });
    }
}
