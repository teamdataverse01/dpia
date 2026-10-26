<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\EntityType;
use App\Support\ValidationException;

/** PRD 15 "Vendor-risk rule" acceptance criteria (section 7.1). */
final class VendorRiskRuleTest extends IntegrationTestCase
{
    private function newDpia(): int
    {
        return $this->app()->assessments()->create($this->user('dev.requestor'), EntityType::ProcessingActivity, [
            'name' => 'Test activity', 'department_id' => 1, 'purpose' => 'Testing.', 'description' => 'Testing the rule.',
        ]);
    }

    public function testValidationRejectsVendorWithoutSharing(): void
    {
        $this->inRollback(function () {
            $id = $this->newDpia();
            $jamb = (int) $this->entityByName('JAMB (Joint Admissions and Matriculation Board)')['id'];
            try {
                $this->app()->assessments()->saveFactors($this->user('dev.requestor'), $id, self::studentApplicationFactors(false, $jamb));
                self::fail('expected ValidationException');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('vendor_risk_ref', $e->fields);
            }
            self::assertNull($this->app()->entities()->factorInputs($id), 'nothing stored');
        });
    }

    public function testDatabaseCheckConstraintRejectsVendorWithoutSharing(): void
    {
        $this->inRollback(function () {
            $id = $this->newDpia();
            $jamb = (int) $this->entityByName('JAMB (Joint Admissions and Matriculation Board)')['id'];
            $db = $this->app()->db();
            $db->pdo()->exec('SAVEPOINT ck');
            try {
                $db->insert('risk_factor_inputs', [
                    'risk_entity_id' => $id, 'data_type' => '["contact"]', 'data_classification' => 'internal', 'data_volume' => 'under_10',
                    'data_subject_type' => 'staff', 'cross_border_transfer' => 'none', 'third_party_transfer' => 'none',
                    'involves_data_sharing' => false, 'nature_of_processing' => 'routine_admin', 'automated_decision_making' => 'none',
                    'vendor_risk_ref' => $jamb,
                ]);
                self::fail('expected the CHECK constraint to reject the row');
            } catch (\PDOException $e) {
                self::assertStringContainsString('ck_vendor_ref_requires_sharing', $e->getMessage());
            } finally {
                $db->pdo()->exec('ROLLBACK TO SAVEPOINT ck');
            }
        });
    }

    public function testSharingRequiresAVendor(): void
    {
        $this->inRollback(function () {
            $id = $this->newDpia();
            $this->expectException(ValidationException::class);
            $this->app()->assessments()->saveFactors($this->user('dev.requestor'), $id, self::studentApplicationFactors(true, null));
        });
    }

    public function testDpiaWithoutSharingNeverIncludesVendorFactor(): void
    {
        $this->inRollback(function () {
            $id = $this->newDpia();
            $r = $this->app()->assessments()->saveFactors($this->user('dev.requestor'), $id, self::studentApplicationFactors(false, null));
            self::assertSame(3.83, $r->score); // PRD 8.6
            $stored = $this->app()->assessments()->storedInherent($id);
            self::assertNotContains('vendor_risk', array_column($stored['breakdown'], 'factor'));
            self::assertSame('vendor_risk', $stored['excluded_factors'][0]['factor']);
            $rows = $this->app()->db()->fetchAll("SELECT * FROM factor_score_breakdown WHERE risk_entity_id = :id AND factor = 'vendor_risk'", ['id' => $id]);
            self::assertSame([], $rows);
        });
    }

    public function testDpiaWithSharingIs385AndPicksUpVendorChangesOnNextCalculation(): void
    {
        $this->inRollback(function () {
            $id = $this->newDpia();
            $jamb = $this->entityByName('JAMB (Joint Admissions and Matriculation Board)');
            self::assertSame(62, (int) $jamb['residual_score_100']); // → factor 4

            $r = $this->app()->assessments()->saveFactors($this->user('dev.requestor'), $id, self::studentApplicationFactors(true, (int) $jamb['id']));
            self::assertSame(3.85, $r->score); // PRD 8.5
            self::assertSame('high', $r->rating->value);

            // The vendor's score changes (e.g. after its controls improved) …
            $this->app()->entities()->update((int) $jamb['id'], ['residual_score_100' => 15]); // → factor 1
            self::assertSame('3.85', (string) $this->app()->entities()->find($id)['inherent_score'], 'no automatic propagation (Phase 2)');

            // … and the DPIA reflects it the next time it is calculated.
            $again = $this->app()->assessments()->score($this->user('dev.requestor'), $id);
            self::assertSame(3.55, $again['inherent']->score); // 3.85 − 0.10 × (4 − 1)
        });
    }

    public function testVendorAssessmentRejectsLegalBasisAndSharing(): void
    {
        $this->inRollback(function () {
            $req = $this->user('dev.requestor');
            $id = $this->app()->assessments()->create($req, EntityType::Vendor, ['name' => 'V', 'department_id' => 1, 'service_description' => 'Svc']);
            try {
                $this->app()->assessments()->saveFactors($req, $id, self::studentApplicationFactors(false, null));
                self::fail('legal basis must be rejected for vendors');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('legal_basis', $e->fields);
            }
            $f = self::studentApplicationFactors(false, null);
            unset($f['legal_basis']);
            $r = $this->app()->assessments()->saveFactors($req, $id, $f);
            self::assertSame(3.88, $r->score);
            self::assertNotNull($this->app()->entities()->find($id)['residual_score_100']);
        });
    }
}
