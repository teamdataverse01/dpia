<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\ForbiddenException;
use App\Support\ValidationException;

/** PRD 15 "Fast path" acceptance criteria (section 10). */
final class FastPathTest extends IntegrationTestCase
{
    private function templateId(string $name): int
    {
        return (int) $this->app()->db()->fetchValue('SELECT id FROM activity_templates WHERE activity_name = :n', ['n' => $name]);
    }

    private const SUBJECT = ['purpose' => 'Admit candidates.', 'description' => 'Records come from JAMB and are ranked.'];

    public function testJambTemplatePreviewShows385HighAndStandardControlsImmediately(): void
    {
        $p = $this->app()->templates()->preview($this->templateId('Admissions — JAMB data intake'));
        self::assertSame(3.85, $p['live']->score);
        self::assertSame('high', $p['live']->rating->value);
        self::assertSame('3.85', (string) $p['template']['inherent_score']);
        self::assertCount(10, $p['controls']);
        self::assertFalse($p['stale']);
        foreach (\App\Services\FactorCatalog::OPTION_FIELDS as $f) {
            self::assertNotEmpty($p['factors'][$f], "{$f} prefilled");
        }
    }

    public function testAcceptPrefillsEverythingAndGoesStraightToControls(): void
    {
        $this->inRollback(function () {
            $tid = $this->templateId('Admissions — JAMB data intake');
            $id = $this->app()->templates()->startFromTemplate($this->user('dev.requestor'), $tid, 'accept', self::SUBJECT, false);
            $e = $this->app()->entities()->find($id);
            self::assertSame('3.85', (string) $e['inherent_score']);
            self::assertSame('high', $e['inherent_rating']);
            self::assertSame('controls_in_progress', $e['status']);
            self::assertSame($tid, (int) $e['template_id']);
            self::assertFalse($e['customised']);
            $assessed = array_column($this->app()->controls()->assessmentsFor($id), 'control_id');
            foreach ($this->app()->controls()->templateControlIds($tid) as $cid) {
                self::assertContains($cid, $assessed, 'standard control prefilled');
            }
        });
    }

    public function testCustomiseMarksCustomisedOnlyWhenSomethingChangesAndRescores(): void
    {
        $this->inRollback(function () {
            $req = $this->user('dev.requestor');
            $tid = $this->templateId('Admissions — JAMB data intake');
            $id = $this->app()->templates()->startFromTemplate($req, $tid, 'customise', self::SUBJECT, false);
            $e = $this->app()->entities()->find($id);
            self::assertSame('scored', $e['status']);
            self::assertFalse($e['customised']);

            $factors = $this->app()->entities()->factorInputs($id);
            $same = array_intersect_key($factors, array_flip(array_merge(\App\Services\FactorCatalog::OPTION_FIELDS, ['data_type', 'involves_data_sharing', 'vendor_risk_ref'])));
            $this->app()->assessments()->saveFactors($req, $id, $same);
            self::assertFalse($this->app()->entities()->find($id)['customised'], 'saving unchanged answers is not a customisation');

            $changed = $same;
            $changed['automated_decision_making'] = 'none'; // 4 → 1
            $r = $this->app()->assessments()->saveFactors($req, $id, $changed);
            self::assertSame(3.40, $r->score); // 3.85 − 0.15 × 3
            self::assertTrue($this->app()->entities()->find($id)['customised']);
        });
    }

    public function testStaleTemplateRequiresConfirmation(): void
    {
        $this->inRollback(function () {
            $tid = $this->templateId('Human Resources — Staff onboarding');
            $p = $this->app()->templates()->preview($tid);
            self::assertTrue($p['stale']);
            self::assertStringContainsString('12 months', $p['stale_reasons'][0]);
            try {
                $this->app()->templates()->startFromTemplate($this->user('dev.requestor'), $tid, 'accept', self::SUBJECT, false);
                self::fail('expected confirmation to be required');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('confirm_stale', $e->fields);
            }
            $id = $this->app()->templates()->startFromTemplate($this->user('dev.requestor'), $tid, 'accept', self::SUBJECT, true);
            self::assertSame('2.17', (string) $this->app()->entities()->find($id)['inherent_score']);
        });
    }

    public function testTemplateBecomesStaleWhenFrameworkVersionChanges(): void
    {
        $this->inRollback(function () {
            $this->app()->admin()->saveConfig($this->user('dev.admin'), \App\Engine\ScoringConfig::defaults(), 'test');
            $p = $this->app()->templates()->preview($this->templateId('Admissions — JAMB data intake'));
            self::assertTrue($p['stale']);
            self::assertStringContainsString('version 1', $p['stale_reasons'][0]);
            $this->app()->templates()->rescoreAll($this->user('dev.admin'));
            self::assertFalse($this->app()->templates()->preview($this->templateId('Admissions — JAMB data intake'))['stale']);
        });
    }

    public function testMatchingIsDeterministicAndExplained(): void
    {
        $byName = $this->app()->templates()->match(1, '  admissions - jamb data intake ', null);
        self::assertSame('name_department', $byName[0]->matchType);
        self::assertStringContainsString('exactly match', $byName[0]->reason);

        $bySignature = $this->app()->templates()->match(null, 'Something else', self::studentApplicationFactors(true, null));
        self::assertSame('signature', $bySignature[0]->matchType);
        self::assertSame('Admissions — JAMB data intake', $bySignature[0]->template->activityName);

        self::assertSame([], $this->app()->templates()->match(1, 'Unknown activity', null));
    }

    public function testSaveAsTemplateAfterApprovalByPrivacyTeamOnly(): void
    {
        $this->inRollback(function () {
            $e = $this->entityByName('Student wellbeing survey'); // approved → monitoring in the seed
            try {
                $this->app()->templates()->saveFromEntity($this->user('dev.requestor'), (int) $e['id'], 'Research — Wellbeing survey');
                self::fail('requestor may not save templates');
            } catch (ForbiddenException) {
            }
            $tid = $this->app()->templates()->saveFromEntity($this->user('dev.privacy_dpo'), (int) $e['id'], 'Research — Wellbeing survey');
            $t = $this->app()->templatesRepo()->find($tid);
            self::assertSame((string) $e['inherent_score'], (string) $t['inherent_score']);
            self::assertNotEmpty($this->app()->controls()->templateControlIds($tid));

            $notApproved = $this->entityByName('Fee payment processing');
            $this->expectException(\App\Support\ConflictException::class);
            $this->app()->templates()->saveFromEntity($this->user('dev.privacy_dpo'), (int) $notApproved['id'], 'X');
        });
    }
}
