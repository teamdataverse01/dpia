<?php

declare(strict_types=1);

use App\App;
use App\Domain\ControlCategory;
use App\Domain\EntityType;
use App\Domain\PortalUser;
use App\Domain\Role;
use App\Engine\InherentRiskCalculator;
use App\Engine\TemplateMatcher;
use App\Infrastructure\DevAuthProvider;

/**
 * DEVELOPMENT demo data (PRD 13): dev users for each role, sample vendors, DPIAs at every workflow
 * stage, activity templates and remediation actions. Everything goes through the real services, so
 * scores, statuses and the audit trail are produced exactly as in normal use.
 *
 * The one exception is a single historical risk_snapshots row set (~3 months ago), inserted directly so
 * the dashboard "trend" story has something to compare against. It is synthetic demo data.
 */
final class DemoSeeder
{
    /** @var array<string,PortalUser> */
    private array $u = [];

    /** @param callable(string):void $out */
    public function __construct(private readonly App $app, private $out)
    {
    }

    private function say(string $s): void
    {
        ($this->out)($s);
    }

    public function run(): void
    {
        $db = $this->app->db();
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM risk_entities') > 0) {
            $this->say('demo data already present, skipped');
            return;
        }
        $dept = [];
        foreach ($this->app->refs()->departments() as $d) {
            $dept[$d['name']] = $d['id'];
        }

        // --- dev users -------------------------------------------------------------------
        $map = [
            'dev.requestor' => [Role::Requestor, null],
            'dev.requestor2' => [Role::Requestor, null],
            'dev.vendor' => [Role::Vendor, null],
            'dev.business_owner' => [Role::BusinessOwner, null],
            'dev.infosec' => [Role::InfoSec, null],
            'dev.privacy_dpo' => [Role::PrivacyDpo, null],
            'dev.reviewer' => [Role::Reviewer, null],
            'dev.risk_approver' => [Role::RiskApprover, null],
            'dev.admin' => [Role::Admin, null],
            'dev.auditor' => [Role::Auditor, null],
        ];
        foreach ($map as $id => [$role, $deptId]) {
            $this->app->refs()->addUserRole($id, $role, $deptId);
            [$name, $email] = DevAuthProvider::USERS[$id];
            $this->u[$id] = new PortalUser($id, $name, $email, [$role], [$role->value => $deptId]);
        }
        $req = $this->u['dev.requestor'];   // Ada: owns the records the demo script walks through
        $req2 = $this->u['dev.requestor2']; // Tunde: owns the rest, so each requestor sees only their own
        $this->say('dev users and roles created');

        // --- vendors -----------------------------------------------------------------------
        $jamb = $this->vendor($req, 'JAMB (Joint Admissions and Matriculation Board)', $dept['Admissions'], [
            'legal_name' => 'Joint Admissions and Matriculation Board',
            'service_description' => 'Supplies UTME candidate records and receives admission decisions through the CAPS portal.',
            'contract_start' => '2024-01-01', 'contract_end' => $this->days(+75),
            'contact_name' => 'JAMB Liaison Office', 'contact_email' => 'liaison@jamb.example.ng',
            'vendor_portal_user_id' => 'dev.vendor', 'subprocessors' => 'None declared',
        ], [
            'data_type' => ['identity', 'contact', 'academic'], 'data_classification' => 'confidential', 'data_volume' => '10000_plus',
            'automated_decision_making' => 'human_reviews', 'cross_border_transfer' => 'none', 'third_party_transfer' => 'several_external',
            'nature_of_processing' => 'large_scale_routine', 'data_subject_type' => 'prospective_students_minors',
        ]);
        $this->app->controlService()->startControls($this->u['dev.infosec'], $jamb);
        $this->control($jamb, 'Data Protection Policy', 'in_progress', 50, false);

        $paystack = $this->vendor($req, 'Paystack', $dept['Bursary / Finance'], [
            'legal_name' => 'Paystack Payments Limited',
            'service_description' => 'Online collection of tuition and hostel fees.',
            'contract_start' => '2025-02-01', 'contract_end' => '2027-01-31',
            'contact_name' => 'Account Manager', 'contact_email' => 'cu-account@paystack.example.com',
        ], [
            'data_type' => ['contact', 'financial'], 'data_classification' => 'confidential', 'data_volume' => '10000_plus',
            'automated_decision_making' => 'assists_human', 'cross_border_transfer' => 'adequate_safeguards', 'third_party_transfer' => 'one_external',
            'nature_of_processing' => 'large_scale_routine', 'data_subject_type' => 'adult_students',
        ]);
        $this->app->controlService()->startControls($this->u['dev.infosec'], $paystack);
        $this->clear($paystack, ControlCategory::Process);
        $this->clear($paystack, ControlCategory::Technology);
        $this->decideFlow($paystack, [['dev.reviewer', 'approved', 'Process and technology controls verified; residual risk acceptable.']]);

        $m365 = $this->vendor($req, 'Microsoft 365', $dept['ICT'], [
            'legal_name' => 'Microsoft Nigeria Ltd',
            'service_description' => 'E-mail, document storage and collaboration for staff and students.',
            'contract_start' => '2023-09-01', 'contract_end' => $this->days(+40),
            'contact_name' => 'Partner Desk', 'contact_email' => 'partner@ms.example.com',
            'subprocessors' => 'Global data-centre sub-processors (list on vendor site)',
        ], [
            'data_type' => ['contact', 'online', 'academic'], 'data_classification' => 'confidential', 'data_volume' => '10000_plus',
            'automated_decision_making' => 'none', 'cross_border_transfer' => 'contractual_safeguards', 'third_party_transfer' => 'subprocessors_unknown',
            'nature_of_processing' => 'large_scale_routine', 'data_subject_type' => 'staff',
        ]);
        $this->app->assessments()->calculateResidual($this->u['dev.infosec'], $m365);
        $this->app->approvals()->submit($req, $m365);

        $turnitin = $this->vendor($req2, 'Turnitin', $dept['Registry'], [
            'legal_name' => 'Turnitin LLC',
            'service_description' => 'Plagiarism checking of student theses and assignments.',
            'contract_end' => '2027-06-30',
        ], [
            'data_type' => ['academic', 'contact'], 'data_classification' => 'confidential', 'data_volume' => '1000_9999',
            'automated_decision_making' => 'assists_human', 'cross_border_transfer' => 'contractual_safeguards', 'third_party_transfer' => 'one_external',
            'nature_of_processing' => 'structured_collection', 'data_subject_type' => 'adult_students',
        ]);
        $this->say('vendors created');

        // --- activity templates -------------------------------------------------------------
        $jambFactors = [
            'data_type' => ['identity', 'contact', 'academic'], 'data_classification' => 'confidential', 'data_volume' => '10000_plus',
            'automated_decision_making' => 'largely_automated', 'cross_border_transfer' => 'adequate_safeguards',
            'third_party_transfer' => 'several_external', 'nature_of_processing' => 'profiling_monitoring',
            'data_subject_type' => 'prospective_students_minors', 'legal_basis' => 'consent',
        ];
        $tJamb = $this->template($dept['Admissions'], 'Admissions — JAMB data intake', $jambFactors, true, $jamb, [
            'Data Protection Policy', 'Data Processing Agreement with the recipient', 'Data Retention Schedule',
            'Periodic Access Review', 'Incident Response Procedure', 'Approval workflow for data sharing', 'Human review of automated decisions',
            'Encryption at rest and in transit', 'Role-based access control with MFA', 'Audit logging / DLP',
        ], $this->days(-60));
        $this->template($dept['Registry'], 'Registry — Transcript requests', [
            'data_type' => ['academic', 'identity', 'contact'], 'data_classification' => 'confidential', 'data_volume' => '1000_9999',
            'automated_decision_making' => 'none', 'cross_border_transfer' => 'adequate_safeguards', 'third_party_transfer' => 'none',
            'nature_of_processing' => 'routine_admin', 'data_subject_type' => 'adult_students', 'legal_basis' => 'contract',
        ], false, null, ['Data Protection Policy', 'Periodic Access Review', 'Encryption at rest and in transit'], $this->days(-30));
        $this->template($dept['Human Resources'], 'Human Resources — Staff onboarding', [
            'data_type' => ['identity', 'contact', 'financial'], 'data_classification' => 'confidential', 'data_volume' => '100_999',
            'automated_decision_making' => 'none', 'cross_border_transfer' => 'none', 'third_party_transfer' => 'none',
            'nature_of_processing' => 'structured_collection', 'data_subject_type' => 'staff', 'legal_basis' => 'contract',
        ], false, null, ['Data Protection Policy', 'Data Retention Schedule', 'Role-based access control with MFA'], '2025-06-01'); // stale on purpose
        $this->say('activity templates created');

        // --- DPIAs ------------------------------------------------------------------------------
        // A. Fast path: the PRD 8.5 example via the JAMB template (Accept).
        $a = $this->app->templates()->startFromTemplate($req, $tJamb, 'accept', [
            'name' => 'Processing Student Applications',
            'purpose' => 'Admit qualified candidates for the 2026/27 session.',
            'description' => 'Candidate records are received from JAMB, screened, ranked and admission decisions returned through CAPS.',
            'retention_period' => '6 years after admission decision',
        ], false);
        $ea = $this->app->entities()->find($a);
        $this->say(sprintf('DPIA "Processing Student Applications": inherent %s / %s (expected 3.85 / high)', $ea['inherent_score'], $ea['inherent_rating']));
        $this->clear($a, ControlCategory::Technology);
        $this->clear($a, ControlCategory::Process);

        // B. Research survey — no data sharing, Medium → Low with Policy, approved.
        $b = $this->dpia($req2, 'Student wellbeing survey', $dept['Research'], [
            'purpose' => 'Understand student wellbeing to plan counselling services.',
            'description' => 'Anonymous-by-default online survey with optional contact details for follow-up.',
        ], [
            'data_type' => ['health', 'contact'], 'data_classification' => 'special_category', 'data_volume' => '1000_9999',
            'automated_decision_making' => 'none', 'cross_border_transfer' => 'none', 'third_party_transfer' => 'none',
            'nature_of_processing' => 'structured_collection', 'data_subject_type' => 'adult_students', 'legal_basis' => 'consent',
        ]);
        $this->app->controlService()->startControls($this->u['dev.privacy_dpo'], $b);
        $this->clear($b, ControlCategory::Policy);
        $this->decideFlow($b, [['dev.reviewer', 'approved', 'Policy controls in place and verified.']]);

        // C. Staff biometric attendance — shares data with a vendor; only Policy cleared → Medium; remediation plan.
        $c = $this->dpia($req2, 'Staff biometric attendance', $dept['Human Resources'], [
            'purpose' => 'Record staff attendance with fingerprint scanners.',
            'description' => 'Fingerprint templates are captured at gates and synced to a cloud attendance service.',
            'retention_period' => 'Duration of employment',
        ], [
            'data_type' => ['biometric', 'identity'], 'data_classification' => 'special_category', 'data_volume' => '1000_9999',
            'automated_decision_making' => 'largely_automated', 'cross_border_transfer' => 'contractual_safeguards',
            'third_party_transfer' => 'one_external', 'nature_of_processing' => 'innovative_combined', 'data_subject_type' => 'staff',
            'legal_basis' => 'legitimate_interest', 'involves_data_sharing' => true, 'vendor_risk_ref' => $m365,
        ]);
        $this->app->controlService()->startControls($this->u['dev.infosec'], $c);
        $this->clear($c, ControlCategory::Policy);
        $this->control($c, 'Encryption at rest and in transit', 'in_progress', 40, false);
        $this->app->assessments()->calculateResidual($this->u['dev.infosec'], $c);
        $rem = $this->app->remediation();
        $rem->create($this->u['dev.infosec'], ['risk_entity_id' => $c, 'gap_description' => 'Enable encryption of fingerprint templates in the cloud attendance service.', 'owner_portal_user_id' => 'dev.infosec', 'priority' => 'high', 'target_date' => $this->days(-21)]);
        $rem->create($this->u['dev.infosec'], ['risk_entity_id' => $c, 'gap_description' => 'Enforce MFA for attendance-system administrators.', 'owner_portal_user_id' => 'dev.infosec', 'priority' => 'medium', 'target_date' => $this->days(+30)]);
        $done = $rem->create($this->u['dev.infosec'], ['risk_entity_id' => $c, 'gap_description' => 'Publish staff privacy notice for biometric attendance.', 'owner_portal_user_id' => 'dev.privacy_dpo', 'priority' => 'low', 'target_date' => $this->days(-5)]);
        $rem->update($this->u['dev.infosec'], $done, ['status' => 'completed']);

        // D. Exam results — Low; negligible residual confirmed → Very Low; approved.
        $d = $this->dpia($req2, 'Exam results publication', $dept['Registry'], [
            'purpose' => 'Publish semester results to students on the portal.',
            'description' => 'Results are released on each student\'s own portal page; no public lists.',
        ], [
            'data_type' => ['academic'], 'data_classification' => 'internal', 'data_volume' => '10000_plus',
            'automated_decision_making' => 'none', 'cross_border_transfer' => 'none', 'third_party_transfer' => 'none',
            'nature_of_processing' => 'routine_admin', 'data_subject_type' => 'adult_students', 'legal_basis' => 'legal_obligation',
        ]);
        $this->app->assessments()->confirmNegligible($this->u['dev.reviewer'], $d, 'Results are only visible to the student themselves on an authenticated page; remaining risk is negligible.');
        $this->decideFlow($d, [['dev.reviewer', 'approved', 'Very low remaining risk confirmed.']]);

        // E. Fee payments — shares with Paystack; scored only.
        $this->dpia($req, 'Fee payment processing', $dept['Bursary / Finance'], [
            'purpose' => 'Collect tuition and accommodation fees.',
            'description' => 'Students pay through the portal; the payment provider confirms transactions to Bursary.',
        ], [
            'data_type' => ['financial', 'contact', 'identity'], 'data_classification' => 'confidential', 'data_volume' => '10000_plus',
            'automated_decision_making' => 'assists_human', 'cross_border_transfer' => 'adequate_safeguards', 'third_party_transfer' => 'one_external',
            'nature_of_processing' => 'large_scale_routine', 'data_subject_type' => 'adult_students', 'legal_basis' => 'contract',
            'involves_data_sharing' => true, 'vendor_risk_ref' => $paystack,
        ]);

        // F. CCTV with facial recognition — High, controls in progress.
        $f = $this->dpia($req, 'Campus CCTV with facial recognition', $dept['ICT'], [
            'purpose' => 'Campus security.',
            'description' => 'Cameras at gates match faces against a watch-list.',
        ], [
            'data_type' => ['biometric', 'location'], 'data_classification' => 'special_category', 'data_volume' => '10000_plus',
            'automated_decision_making' => 'largely_automated', 'cross_border_transfer' => 'none', 'third_party_transfer' => 'internal_affiliate',
            'nature_of_processing' => 'innovative_combined', 'data_subject_type' => 'minors_vulnerable', 'legal_basis' => 'legitimate_interest',
        ]);
        $this->app->controlService()->startControls($this->u['dev.infosec'], $f);
        $this->control($f, 'Incident Response Procedure', 'implemented', 60, true);

        // G. Draft only.
        $this->app->assessments()->create($req2, EntityType::ProcessingActivity, [
            'name' => 'Alumni newsletter mailing list', 'department_id' => $dept['Registry'],
            'purpose' => 'Keep alumni informed.', 'description' => 'Alumni sign up to receive a quarterly newsletter.',
        ]);
        $this->say('DPIAs created');

        // --- synthetic trend history, then the real daily job --------------------------------------
        $past = $this->days(-95);
        foreach ([['high', 'high', 5], ['medium', 'high', 2], ['medium', 'medium', 3], ['low', 'medium', 1]] as [$res, $inh, $n]) {
            $db->insert('risk_snapshots', ['snapshot_date' => $past, 'department_id' => null, 'entity_type' => 'processing_activity', 'residual_rating' => $res, 'inherent_rating' => $inh, 'risk_count' => $n]);
        }
        $stats = $this->app->jobs()->daily();
        $this->say('daily job: ' . json_encode($stats));
        $this->say('demo data ready');
    }

    private function days(int $n): string
    {
        return $this->app->clock()->today()->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    private function vendor(PortalUser $by, string $name, int $dept, array $profile, array $factors): int
    {
        $id = $this->app->assessments()->create($by, EntityType::Vendor, ['name' => $name, 'department_id' => $dept] + $profile);
        $this->app->assessments()->saveFactors($by, $id, $factors);
        return $id;
    }

    private function dpia(PortalUser $by, string $name, int $dept, array $profile, array $factors): int
    {
        $id = $this->app->assessments()->create($by, EntityType::ProcessingActivity, ['name' => $name, 'department_id' => $dept] + $profile);
        $this->app->assessments()->saveFactors($by, $id, $factors);
        return $id;
    }

    /** Record one control by title. */
    private function control(int $entityId, string $title, string $status, int $eff, bool $evidence): void
    {
        $cs = $this->app->controlService();
        foreach ($cs->overview($this->app->entities()->find($entityId)) as $row) {
            if ($row['control']['title'] === $title) {
                $cs->record($this->u['dev.infosec'], $entityId, $row['control']['id'], [
                    'status' => $status, 'effectiveness_pct' => (string) $eff, 'evidence_owner' => 'Information Security',
                    'expiry_date' => $this->days(+300),
                ], $evidence ? $this->evidenceFile($title) : null);
                return;
            }
        }
        throw new RuntimeException("Control {$title} not recommended for entity {$entityId}");
    }

    /** Make every recommended mandatory control in a category meet the clearance criteria. */
    private function clear(int $entityId, ControlCategory $category): void
    {
        $cs = $this->app->controlService();
        foreach ($cs->overview($this->app->entities()->find($entityId)) as $row) {
            if ($row['control']['category'] !== $category->value || !$row['counts_for_clearance'] || (!$row['mandatory_for_clearance'] && $row['source'] !== 'manual')) {
                continue;
            }
            $cid = $row['control']['id'];
            $cs->record($this->u['dev.infosec'], $entityId, $cid, [
                'status' => 'implemented', 'effectiveness_pct' => '85', 'evidence_owner' => 'Control owner',
                'expiry_date' => $this->days($category === ControlCategory::Technology ? +25 : +200),
                'notes' => 'Demo evidence.',
            ], $this->evidenceFile($row['control']['title']));
            if ($row['control']['verification_required']) {
                $cs->verify($this->u['dev.reviewer'], $entityId, $cid);
            }
        }
    }

    private function evidenceFile(string $title): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ev');
        file_put_contents($tmp, "Demo evidence for: {$title}\nGenerated by the demo seeder.\n");
        return ['tmp_name' => $tmp, 'name' => preg_replace('/[^A-Za-z0-9]+/', '-', $title) . '.txt', 'error' => 0];
    }

    /** Residual → submit → decisions. */
    private function decideFlow(int $entityId, array $decisions): void
    {
        $owner = $this->u[$this->app->entities()->find($entityId)['owner_portal_user_id']]; // the record's owner submits it
        $this->app->assessments()->calculateResidual($owner, $entityId);
        $this->app->approvals()->submit($owner, $entityId);
        foreach ($decisions as [$userId, $decision, $why]) {
            $this->app->approvals()->decide($this->u[$userId], $entityId, $decision, $why, null);
        }
    }

    /** Create a template, scoring it with the engine so its stored score matches the framework. */
    private function template(int $dept, string $name, array $factors, bool $sharing, ?int $vendorId, array $controlTitles, string $reviewed): int
    {
        $full = $factors + ['involves_data_sharing' => $sharing, 'vendor_risk_ref' => $vendorId];
        $catalog = $this->app->catalog();
        $norm = $catalog->normalise(EntityType::ProcessingActivity, $full);
        $config = $this->app->configs()->active();
        $r = (new InherentRiskCalculator())->calculate(EntityType::ProcessingActivity, $sharing, $catalog->selections(EntityType::ProcessingActivity, $norm), $config);

        $controlIds = [];
        foreach ($this->app->controls()->all() as $c) {
            if (in_array($c['title'], $controlTitles, true)) {
                $controlIds[] = $c['id'];
            }
        }
        $snapshot = $norm;
        unset($snapshot['involves_data_sharing'], $snapshot['vendor_risk_ref']);
        $repo = $this->app->templatesRepo();
        $id = $repo->create([
            'department_id' => $dept,
            'activity_name' => $name,
            'data_type_signature' => TemplateMatcher::dataTypeSignature($norm['data_type']),
            'data_subject_signature' => TemplateMatcher::dataSubjectSignature($norm['data_subject_type']),
            'characteristics_signature' => TemplateMatcher::characteristicsSignature($norm['nature_of_processing'], $norm['automated_decision_making'], $norm['cross_border_transfer'], $norm['third_party_transfer']),
            'involves_data_sharing' => $sharing,
            'default_vendor_entity_id' => $vendorId,
            'inherent_score' => number_format($r->score, 2, '.', ''),
            'inherent_rating' => $r->rating->value,
            'factor_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'framework_version' => $config->version,
            'last_reviewed_at' => $reviewed,
            'created_by' => 'dev.privacy_dpo',
        ]);
        $repo->setControls($id, $controlIds);
        $this->app->audit()->record('dev.privacy_dpo', 'activity_templates', $id, 'create', null, ['activity_name' => $name, 'inherent_score' => $r->score]);
        $this->say(sprintf('template "%s": %.2f / %s', $name, $r->score, $r->rating->value));
        return $id;
    }
}
