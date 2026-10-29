<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Domain\AssessmentStatus as St;
use App\Domain\ControlCategory;
use App\Domain\ControlStatus;
use App\Domain\EntityType;
use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\Rating;
use App\Engine\VendorRiskMapper;
use App\Http\Request;
use App\Http\Response;
use App\Services\FactorCatalog;
use App\Support\ValidationException;

/** The ten shared wizard screens (PRD 6) for both Vendor and DPIA assessments. */
final class WizardController extends BaseController
{
    /** Fields captured on "Risk Factors" (screen 2); the rest are on "Third-Party & Transfers" (screen 3). */
    private const SCREEN2 = ['data_type', 'data_classification', 'data_volume', 'data_subject_type', 'nature_of_processing', 'automated_decision_making', 'legal_basis'];
    private const SCREEN3 = ['cross_border_transfer', 'third_party_transfer', 'involves_data_sharing', 'vendor_risk_ref'];

    public const STEPS = [
        'subject' => 'Subject',
        'factors' => 'Risk factors',
        'transfers' => 'Third-party & transfers',
        'inherent' => 'Inherent risk',
        'recommended' => 'Recommended controls',
        'controls' => 'Control assessment',
        'residual' => 'Residual risk',
        'remediation' => 'Remediation plan',
        'review' => 'Review & approval',
        'monitoring' => 'Monitoring',
    ];

    // --- helpers ---------------------------------------------------------------------------

    private function load(PortalUser $user, Request $request): array
    {
        return $this->app->assessments()->getForUser($user, $request->intParam('id'));
    }

    /** Common variables for every screen. */
    private function ctx(PortalUser $user, array $e, string $step): array
    {
        $authz = $this->app->authz();
        $editable = St::from($e['status'])->isEditable();
        return [
            'e' => $e,
            'step' => $step,
            'type' => EntityType::from($e['entity_type']),
            'editable' => $editable,
            'canEditOwn' => $editable && $authz->can($user, P::EditOwn, $e),
            'canEditControls' => $editable && $authz->can($user, P::EditControls, $e),
            'title' => $e['name'],
        ];
    }

    private function draftKey(int $id): string
    {
        return 'factor_draft_' . $id;
    }

    /** Factor values for the form: session draft (unsaved screen 2) over saved values. */
    private function factorValues(int $id): array
    {
        $saved = $this->app->entities()->factorInputs($id) ?? [];
        $draft = $_SESSION[$this->draftKey($id)] ?? [];
        return $draft + $saved;
    }

    private static function only(array $in, array $keys): array
    {
        return array_intersect_key($in, array_flip($keys));
    }

    // --- stage 1: intake ------------------------------------------------------------------------

    public function newVendor(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, P::Create);
        return $this->render('wizard/intake', ['type' => EntityType::Vendor, 'old' => [], 'errors' => [], 'departments' => $this->app->refs()->departments(), 'title' => 'New vendor risk assessment']);
    }

    public function newDpia(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, P::Create);
        $old = ['name' => (string) $request->query('name', ''), 'department_id' => (string) $request->query('department_id', '')];
        return $this->render('wizard/intake', ['type' => EntityType::ProcessingActivity, 'old' => $old, 'errors' => [], 'departments' => $this->app->refs()->departments(), 'title' => 'New DPIA']);
    }

    public function createVendor(Request $request, PortalUser $user): Response
    {
        return $this->create($request, $user, EntityType::Vendor);
    }

    public function createDpia(Request $request, PortalUser $user): Response
    {
        return $this->create($request, $user, EntityType::ProcessingActivity);
    }

    private function create(Request $request, PortalUser $user, EntityType $type): Response
    {
        try {
            $id = $this->app->assessments()->create($user, $type, $request->body);
        } catch (ValidationException $ex) {
            return $this->render('wizard/intake', ['type' => $type, 'old' => $request->body, 'errors' => $ex->fields, 'departments' => $this->app->refs()->departments(), 'title' => 'New ' . $type->label()], 422);
        }
        $this->flash('success', 'Saved. Now describe the risk factors.');
        return $this->redirect("/assessments/{$id}/factors");
    }

    public function show(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $hasFactors = $this->app->entities()->factorInputs((int) $e['id']) !== null;
        $step = match (St::from($e['status'])) {
            St::Draft => $hasFactors ? 'inherent' : 'factors',
            St::Scored => 'inherent',
            St::ControlsInProgress => 'controls',
            St::ResidualCalculated => 'residual',
            St::PendingApproval, St::Rejected => 'review',
            default => 'monitoring',
        };
        return $this->redirect("/assessments/{$e['id']}/{$step}");
    }

    public function subject(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        return $this->render('wizard/subject', $this->ctx($user, $e, 'subject') + ['old' => $e, 'errors' => [], 'departments' => $this->app->refs()->departments()]);
    }

    public function saveSubject(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        try {
            $this->app->assessments()->updateSubject($user, (int) $e['id'], $request->body);
        } catch (ValidationException $ex) {
            return $this->render('wizard/subject', $this->ctx($user, $e, 'subject') + ['old' => $request->body + $e, 'errors' => $ex->fields, 'departments' => $this->app->refs()->departments()], 422);
        }
        $this->flash('success', 'Details saved.');
        return $this->redirect("/assessments/{$e['id']}/factors");
    }

    // --- stage 2: risk factors (screens 2 and 3) --------------------------------------------------

    public function factors(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        // errors handed back from the Third-Party & Transfers screen (they belong to this screen's fields)
        $errors = $_SESSION['factor_errors_' . $e['id']] ?? [];
        unset($_SESSION['factor_errors_' . $e['id']]);
        return $this->render('wizard/factors', $this->ctx($user, $e, 'factors') + [
            'values' => $this->factorValues((int) $e['id']),
            'errors' => $errors,
            'options' => $this->app->catalog()->options(),
        ]);
    }

    public function saveFactors(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $this->app->authz()->authorize($user, P::EditOwn, $e);
        $id = (int) $e['id'];
        $posted = self::only($request->body, self::SCREEN2);
        $posted['data_type'] ??= [];
        $values = $posted + $this->factorValues($id);
        $type = EntityType::from($e['entity_type']);

        $errors = $this->validationErrors($type, $values, $id, self::SCREEN2);
        if ($errors !== []) {
            return $this->render('wizard/factors', $this->ctx($user, $e, 'factors') + [
                'values' => $values, 'errors' => $errors, 'options' => $this->app->catalog()->options(),
            ], 422);
        }
        $_SESSION[$this->draftKey($id)] = self::only($values, self::SCREEN2);
        return $this->redirect("/assessments/{$id}/transfers");
    }

    /** Errors limited to one screen's fields (the other screen may legitimately still be empty). */
    private function validationErrors(EntityType $type, array $values, int $id, array $fields): array
    {
        try {
            $this->app->catalog()->normalise($type, $values, $id);
            return [];
        } catch (ValidationException $ex) {
            return self::only($ex->fields, $fields);
        }
    }

    public function transfers(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        return $this->renderTransfers($user, $e, $this->factorValues((int) $e['id']), []);
    }

    private function renderTransfers(PortalUser $user, array $e, array $values, array $errors, int $status = 200): Response
    {
        $vendors = array_values(array_filter($this->app->entities()->vendorsForPicker(), fn ($v) => (int) $v['id'] !== (int) $e['id']));
        foreach ($vendors as &$v) {
            $v['score100'] = FactorCatalog::vendorScore100($v);
            $v['factor_score'] = VendorRiskMapper::factorScore($v['score100']);
        }
        unset($v);
        return $this->render('wizard/transfers', $this->ctx($user, $e, 'transfers') + [
            'values' => $values,
            'errors' => $errors,
            'options' => $this->app->catalog()->options(),
            'vendors' => $vendors,
        ], $status);
    }

    public function saveTransfers(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $id = (int) $e['id'];
        $type = EntityType::from($e['entity_type']);
        $posted = self::only($request->body, self::SCREEN3);
        if ($type === EntityType::ProcessingActivity) {
            $posted['involves_data_sharing'] = FactorCatalog::parseBool($posted['involves_data_sharing'] ?? false);
            if (!$posted['involves_data_sharing'] && $request->input('_js') === '1') {
                // with JavaScript the picker is disabled when sharing is "No", so nothing is posted for it
                $posted['vendor_risk_ref'] = null;
            }
        }
        $values = $posted + $this->factorValues($id);

        try {
            $result = $this->app->assessments()->saveFactors($user, $id, $values);
        } catch (ValidationException $ex) {
            if (self::only($ex->fields, self::SCREEN2) !== []) {
                $_SESSION['factor_errors_' . $id] = self::only($ex->fields, self::SCREEN2);
                $this->flash('error', 'Some answers on the Risk factors screen need attention.');
                return $this->redirect("/assessments/{$id}/factors");
            }
            return $this->renderTransfers($user, $e, $values, $ex->fields, 422);
        }
        unset($_SESSION[$this->draftKey($id)]);
        $this->flash('success', sprintf('Inherent risk calculated: %s (%s).', number_format($result->score, 2), $result->rating->label()));
        return $this->redirect("/assessments/{$id}/inherent");
    }

    // --- stage 3: inherent risk -----------------------------------------------------------------

    public function inherent(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $id = (int) $e['id'];
        $stored = $this->app->assessments()->storedInherent($id);
        $factors = $this->app->entities()->factorInputs($id);
        $config = $this->app->configs()->active();
        $vendor = null;
        if ($factors !== null && $factors['involves_data_sharing'] && $factors['vendor_risk_ref']) {
            $vendor = $this->app->entities()->find((int) $factors['vendor_risk_ref']);
        }
        $matches = [];
        if ($e['entity_type'] === EntityType::ProcessingActivity->value && $e['template_id'] === null && $factors !== null) {
            $matches = $this->app->templates()->match(null, null, $factors);
        }
        return $this->render('wizard/inherent', $this->ctx($user, $e, 'inherent') + [
            'stored' => $stored,
            'factors' => $factors,
            'vendor' => $vendor,
            'currentVersion' => $config->version,
            'config' => $config,
            'matches' => $matches,
            'history' => $this->app->entities()->scoreHistory($id),
        ]);
    }

    public function rescore(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $r = $this->app->assessments()->score($user, (int) $e['id']);
        $this->flash('success', sprintf('Recalculated with the current scoring framework: %s (%s).', number_format($r['inherent']->score, 2), $r['inherent']->rating->label()));
        return $this->redirect("/assessments/{$e['id']}/inherent");
    }

    // --- stage 4: recommended controls ---------------------------------------------------------------

    public function recommended(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $rec = $this->app->recommendations()->recommended($e);
        $byCat = [];
        foreach (ControlCategory::cases() as $c) {
            $byCat[$c->value] = [];
        }
        foreach ($rec as $r) {
            $byCat[$r['control']['category']][] = $r;
        }
        $ctx = $this->ctx($user, $e, 'recommended');
        return $this->render('wizard/recommended', $ctx + [
            'byCategory' => $byCat,
            'hasAssessments' => $this->app->controls()->hasAssessments((int) $e['id']),
            'canStart' => $ctx['canEditOwn'] || $ctx['canEditControls'],
        ]);
    }

    public function startControls(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $n = $this->app->controlService()->startControls($user, (int) $e['id']);
        $this->flash('success', $n > 0 ? "{$n} recommended controls added to the assessment." : 'Recommended controls are already in the assessment.');
        return $this->redirect("/assessments/{$e['id']}/controls");
    }

    // --- stage 5: control assessment -----------------------------------------------------------------

    public function controls(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $rows = $this->app->controlService()->overview($e);
        $explanation = $this->app->assessments()->residualExplanation($e);
        $inList = array_map(fn ($r) => $r['control']['id'], $rows);
        $addable = array_values(array_filter($this->app->controls()->all(), fn ($c) => !in_array($c['id'], $inList, true)));
        // open remediation actions per control, so this screen shows who is fixing what (and by when)
        $assigned = [];
        foreach ($this->app->remediation()->list($user, ['risk_entity_id' => (int) $e['id']]) as $a) {
            if ($a['control_id'] !== null && in_array($a['display_status'], ['open', 'in_progress', 'overdue'], true)) {
                $assigned[(int) $a['control_id']] ??= $a + ['owner_name' => $this->app->people()->name($a['owner_portal_user_id'])];
            }
        }
        return $this->render('wizard/controls', $this->ctx($user, $e, 'controls') + [
            'rows' => $rows,
            'assigned' => $assigned,
            'explanation' => $explanation,
            'addable' => $addable,
            'canVerify' => St::from($e['status'])->isEditable() && $this->app->authz()->can($user, P::VerifyEvidence, $e),
            'statuses' => ControlStatus::cases(),
            'minEffectiveness' => $this->app->configs()->active()->clearanceMinEffectiveness,
            'focus' => (int) $request->query('control', 0),
        ]);
    }

    public function recordControl(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $cid = $request->intParam('cid');
        try {
            $this->app->controlService()->record($user, (int) $e['id'], $cid, self::only($request->body, ['status', 'effectiveness_pct', 'evidence_owner', 'evidence_date', 'expiry_date', 'notes']), $request->file('evidence'));
            $fresh = $this->app->entities()->find((int) $e['id']);
            $stillGap = in_array($cid, array_map(fn ($g) => (int) $g['control']['id'], $this->app->controlService()->gaps($fresh)), true);
            $hasOpenAction = $this->app->remediationRepo()->openForControl((int) $e['id'], $cid) !== null;
            $this->flash('success', !$stillGap && $hasOpenAction
                ? 'Control saved — this gap is now fixed. Residual risk updated. Mark its action as completed on the Remediation plan (step 8).'
                : 'Control saved. Residual risk updated.' . ($stillGap ? ' This control still has a gap — see “Still needed”.' : ''));
        } catch (ValidationException $ex) {
            $this->flash('error', $ex->getMessage() . ' ' . implode(' ', $ex->fields));
        }
        return Response::redirect(url("/assessments/{$e['id']}/controls", ['control' => $cid]) . '#control-' . $cid);
    }

    public function addControl(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $cid = (int) $request->input('control_id');
        $this->app->controlService()->addManualControl($user, (int) $e['id'], $cid);
        $this->flash('success', 'Control added.');
        return Response::redirect(url("/assessments/{$e['id']}/controls") . '#control-' . $cid);
    }

    public function verify(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $cid = $request->intParam('cid');
        $verified = $request->input('verified', '1') === '1';
        $this->app->controlService()->verify($user, (int) $e['id'], $cid, $verified);
        $this->flash('success', $verified ? 'Evidence marked as verified.' : 'Verification removed.');
        return Response::redirect(url("/assessments/{$e['id']}/controls") . '#control-' . $cid);
    }

    public function evidence(Request $request, PortalUser $user): Response
    {
        $f = $this->app->controlService()->evidenceFile($user, $request->intParam('id'), $request->intParam('cid'));
        return Response::file($f['path'], $f['name'], $f['mime']);
    }

    // --- stage 6: residual risk ------------------------------------------------------------------------

    public function residual(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $ctx = $this->ctx($user, $e, 'residual');
        return $this->render('wizard/residual', $ctx + [
            'explanation' => $this->app->assessments()->residualExplanation($e),
            'canConfirmNegligible' => $this->app->authz()->can($user, P::ConfirmNegligible, $e) && $e['status'] !== St::PendingApproval->value,
            'canCalculate' => $ctx['canEditOwn'] || $ctx['canEditControls'],
        ]);
    }

    public function calculateResidual(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $r = $this->app->assessments()->calculateResidual($user, (int) $e['id']);
        $this->flash('success', 'Residual risk calculated: ' . $r->rating->label() . '.');
        return $this->redirect("/assessments/{$e['id']}/residual");
    }

    public function negligible(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $confirm = $request->input('confirm', '1') === '1';
        try {
            $r = $this->app->assessments()->confirmNegligible($user, (int) $e['id'], (string) $request->input('justification', ''), $confirm);
            $this->flash('success', $confirm ? 'Negligible remaining risk confirmed. Residual risk is now ' . $r->rating->label() . '.' : 'Confirmation withdrawn.');
        } catch (ValidationException $ex) {
            $this->flash('error', implode(' ', $ex->fields) ?: $ex->getMessage());
        }
        return $this->redirect("/assessments/{$e['id']}/residual");
    }

    // --- stage 7: remediation plan -----------------------------------------------------------------------

    public function remediation(Request $request, PortalUser $user, array $errors = [], array $old = []): Response
    {
        $e = $this->load($user, $request);
        $actions = $this->app->remediation()->list($user, ['risk_entity_id' => (int) $e['id']]);
        $people = $this->app->people();

        // Every gap stays listed; ones that already have an open action show who has it and by when.
        $gaps = $this->app->controlService()->gaps($e);
        $gapIds = [];
        foreach ($gaps as $g) {
            $gapIds[(int) $g['control']['id']] = true;
        }
        $openByControl = [];
        foreach ($actions as &$a) {
            $open = in_array($a['display_status'], ['open', 'in_progress', 'overdue'], true);
            $a['owner_name'] = $people->name($a['owner_portal_user_id']);
            // the control this action was for now meets the criteria → the work is done, close the action
            $a['gap_fixed'] = $open && $a['control_id'] !== null && !isset($gapIds[(int) $a['control_id']]);
            if ($open && $a['control_id'] !== null) {
                $openByControl[(int) $a['control_id']] ??= $a;
            }
        }
        unset($a);
        foreach ($gaps as &$g) {
            $g['action'] = $openByControl[(int) $g['control']['id']] ?? null;
            $g['suggested_owner'] = $people->firstWithRole($g['control']['owner_role']);
        }
        unset($g);

        return $this->render('wizard/remediation', $this->ctx($user, $e, 'remediation') + [
            'actions' => $actions,
            'gaps' => $gaps,
            'people' => $people->all(),
            'counts' => [
                'gaps' => count($gaps),
                'assigned' => count(array_filter($gaps, fn ($g) => $g['action'] !== null)),
                'fixed' => count(array_filter($actions, fn ($a) => $a['gap_fixed'])),
            ],
            'canManage' => $this->app->authz()->can($user, P::ManageRemediation, $e),
            'errors' => $errors,
            'old' => $old,
            'defaultDate' => $this->app->clock()->today()->modify('+30 days')->format('Y-m-d'),
        ], $errors ? 422 : 200);
    }

    public function createAction(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        try {
            $this->app->remediation()->create($user, ['risk_entity_id' => (int) $e['id']] + self::only($request->body, ['control_id', 'gap_description', 'owner_portal_user_id', 'priority', 'target_date', 'reviewer_portal_user_id']));
        } catch (ValidationException $ex) {
            return $this->remediation($request, $user, $ex->fields, $request->body);
        }
        $this->flash('success', 'Remediation action added.');
        return $this->redirect("/assessments/{$e['id']}/remediation");
    }

    // --- stage 8: review and approval -------------------------------------------------------------------

    public function review(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $approvals = $this->app->approvals();
        return $this->render('wizard/review', $this->ctx($user, $e, 'review') + [
            'round' => $approvals->round($e),
            'history' => $this->app->approvalsRepo()->forEntity((int) $e['id']),
            'canSubmit' => $e['status'] === St::ResidualCalculated->value && $this->app->authz()->can($user, P::Submit, $e),
            'canDecide' => $approvals->canDecide($user, $e),
            'canAccept' => $this->app->authz()->can($user, P::Approve),
            'openActions' => count(array_filter($this->app->remediation()->list($user, ['risk_entity_id' => (int) $e['id']]), fn ($a) => in_array($a['display_status'], ['open', 'in_progress', 'overdue'], true))),
            'minAcceptDate' => $this->app->clock()->today()->modify('+1 day')->format('Y-m-d'),
        ]);
    }

    public function submit(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $this->app->approvals()->submit($user, (int) $e['id']);
        $this->flash('success', 'Submitted for approval. The approvers have been notified.');
        return $this->redirect("/assessments/{$e['id']}/review");
    }

    public function decide(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        try {
            $outcome = $this->app->approvals()->decide(
                $user,
                (int) $e['id'],
                (string) $request->input('decision', ''),
                (string) $request->input('justification', ''),
                $request->input('accepted_until'),
            );
        } catch (ValidationException $ex) {
            $this->flash('error', implode(' ', $ex->fields) ?: $ex->getMessage());
            return $this->redirect("/assessments/{$e['id']}/review");
        }
        $this->flash('success', match ($outcome) {
            'approved' => 'Approved. The assessment now moves to monitoring.',
            'rejected' => 'Rejected. The owner has been notified.',
            'risk_accepted' => 'Risk accepted until the date given.',
            default => 'Your approval is recorded. Waiting for the other required approvers.',
        });
        return $this->redirect("/assessments/{$e['id']}/review");
    }

    // --- stage 9: monitoring --------------------------------------------------------------------------

    public function monitoring(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $id = (int) $e['id'];
        $authz = $this->app->authz();
        $today = $this->app->clock()->today()->format('Y-m-d');
        $evidence = array_values(array_filter($this->app->controls()->assessmentsFor($id), fn ($a) => $a['expiry_date'] !== null && !empty($a['evidence_path'])));
        return $this->render('wizard/monitoring', $this->ctx($user, $e, 'monitoring') + [
            'acceptance' => $this->app->approvalsRepo()->latestAcceptance($id),
            'evidence' => $evidence,
            'today' => $today,
            'history' => $this->app->auditRepo()->historyForEntity($id),
            'scores' => $this->app->entities()->scoreHistory($id),
            'linkedDpias' => $e['entity_type'] === 'vendor' ? $this->app->entities()->dpiasLinkedToVendor($id) : [],
            'canReassess' => St::from($e['status'])->isDecided() && ($authz->can($user, P::EditOwn, $e) || $authz->can($user, P::EditControls, $e)),
            'canSaveTemplate' => $e['entity_type'] === 'processing_activity' && St::from($e['status'])->isDecided() && $authz->can($user, P::SaveTemplate),
        ]);
    }

    public function reassess(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $this->app->assessments()->startReassessment($user, (int) $e['id']);
        $this->flash('success', 'Reassessment started. Review the risk factors, then the controls.');
        return $this->redirect("/assessments/{$e['id']}/factors");
    }

    public static function ratingLabel(?string $r): string
    {
        return $r === null ? 'Not scored' : (Rating::tryFrom($r)?->label() ?? $r);
    }
}
