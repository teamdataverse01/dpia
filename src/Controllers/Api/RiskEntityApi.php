<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\App;
use App\Domain\EntityType;
use App\Domain\PortalUser;
use App\Http\Request;
use App\Http\Response;
use App\Support\ValidationException;

final class RiskEntityApi
{
    public function __construct(private readonly App $app)
    {
    }

    public static function entityJson(array $e): array
    {
        $out = [
            'id' => (int) $e['id'],
            'entity_type' => $e['entity_type'],
            'name' => $e['name'],
            'owner_portal_user_id' => $e['owner_portal_user_id'],
            'department_id' => $e['department_id'] === null ? null : (int) $e['department_id'],
            'department' => $e['department_name'] ?? null,
            'status' => $e['status'],
            'inherent_score' => $e['inherent_score'] === null ? null : (float) $e['inherent_score'],
            'inherent_rating' => $e['inherent_rating'],
            'residual_rating' => $e['residual_rating'],
            'residual_score' => $e['residual_score'] === null ? null : (float) $e['residual_score'],
            'residual_score_100' => $e['residual_score_100'] === null ? null : (int) $e['residual_score_100'],
            'risk_reduced_pct' => $e['risk_reduced_pct'] === null ? null : (float) $e['risk_reduced_pct'],
            'cleared_categories' => array_values(array_filter(explode(',', (string) $e['cleared_categories']))),
            'negligible_residual_confirmed' => (bool) $e['negligible_residual_confirmed'],
            'customised' => (bool) $e['customised'],
            'template_id' => $e['template_id'] === null ? null : (int) $e['template_id'],
            'scoring_framework_version' => (int) $e['scoring_framework_version'],
            'next_review_date' => $e['next_review_date'],
            'created_at' => $e['created_at'],
            'updated_at' => $e['updated_at'],
        ];
        if ($e['entity_type'] === EntityType::Vendor->value) {
            $out['vendor_profile'] = [
                'legal_name' => $e['legal_name'], 'service_description' => $e['service_description'],
                'contract_start' => $e['contract_start'], 'contract_end' => $e['contract_end'], 'subprocessors' => $e['subprocessors'],
                'contact_name' => $e['contact_name'], 'contact_email' => $e['contact_email'], 'vendor_portal_user_id' => $e['vendor_portal_user_id'],
            ];
        } else {
            $out['processing_activity'] = ['purpose' => $e['purpose'], 'description' => $e['description'], 'retention_period' => $e['retention_period']];
        }
        return $out;
    }

    private function load(PortalUser $user, Request $request): array
    {
        return $this->app->assessments()->getForUser($user, $request->intParam('id'));
    }

    public function create(Request $request, PortalUser $user): Response
    {
        $type = EntityType::tryFrom((string) $request->input('entity_type', ''));
        if ($type === null) {
            throw new ValidationException('entity_type must be "vendor" or "processing_activity".', ['entity_type' => 'Required.']);
        }
        $id = $this->app->assessments()->create($user, $type, $request->body);
        return Response::json(self::entityJson($this->app->entities()->find($id)), 201);
    }

    public function list(Request $request, PortalUser $user): Response
    {
        $f = [];
        $map = ['type' => 'entity_type', 'entity_type' => 'entity_type', 'department' => 'department_id', 'department_id' => 'department_id', 'rating' => 'rating', 'status' => 'status', 'owner' => 'owner', 'basis' => 'basis', 'q' => 'q'];
        foreach ($map as $in => $key) {
            $v = $request->query($in);
            if ($v !== null && $v !== '') {
                $f[$key] = $key === 'department_id' ? (int) $v : (string) $v;
            }
        }
        $rows = $this->app->entities()->list($f, $this->app->dashboard()->scope($user));
        return Response::json(['data' => array_map([self::class, 'entityJson'], $rows), 'count' => count($rows)]);
    }

    public function show(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $id = (int) $e['id'];
        $controls = array_map(fn ($r) => [
            'control_id' => $r['control']['id'],
            'title' => $r['control']['title'],
            'category' => $r['control']['category'],
            'recommended' => $r['recommended'],
            'source' => $r['source'],
            'reason' => $r['reason'],
            'mandatory_for_clearance' => $r['mandatory_for_clearance'],
            'assessment' => $r['assessment'] === null ? null : array_intersect_key($r['assessment'], array_flip(['status', 'effectiveness_pct', 'evidence_owner', 'evidence_date', 'expiry_date', 'evidence_original_name', 'verified_by', 'verified_at', 'notes'])) + ['has_evidence' => !empty($r['assessment']['evidence_path'])],
            'unmet_criteria' => $r['unmet'],
        ], $this->app->controlService()->overview($e));
        return Response::json([
            'entity' => self::entityJson($e),
            'factors' => $this->app->entities()->factorInputs($id),
            'inherent' => $this->app->assessments()->storedInherent($id),
            'controls' => $controls,
            'residual' => $this->app->assessments()->residualExplanation($e),
            'remediation_actions' => $this->app->remediation()->list($user, ['risk_entity_id' => $id]),
            'approvals' => $this->app->approvalsRepo()->forEntity($id),
            'approval_round' => $this->roundJson($e),
        ]);
    }

    private function roundJson(array $e): array
    {
        $round = $this->app->approvals()->round($e);
        return [
            'required_roles' => array_map(fn ($r) => $r->value, $round['required']),
            'approved_roles' => $round['approved_roles'],
            'outstanding_roles' => array_map(fn ($r) => $r->value, $round['outstanding']),
        ];
    }

    public function factors(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $r = $this->app->assessments()->saveFactors($user, (int) $e['id'], $request->body);
        return Response::json(['inherent' => $r->toArray(), 'entity' => self::entityJson($this->app->entities()->find((int) $e['id']))]);
    }

    public function preview(Request $request, PortalUser $user): Response
    {
        $type = EntityType::tryFrom((string) $request->input('entity_type', '')) ?? EntityType::ProcessingActivity;
        $self = $request->input('risk_entity_id');
        $r = $this->app->assessments()->preview($user, $type, $request->body, $self === null || $self === '' ? null : (int) $self);
        return Response::json(['inherent' => $r->toArray()]);
    }

    public function score(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $r = $this->app->assessments()->score($user, (int) $e['id']);
        $fresh = $this->app->entities()->find((int) $e['id']);
        return Response::json([
            'inherent' => $r['inherent']->toArray(),
            'residual' => $this->app->assessments()->residualExplanation($fresh),
            'entity' => self::entityJson($fresh),
        ]);
    }

    public function recommended(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $out = [];
        foreach ($this->app->recommendations()->recommended($e) as $rec) {
            $c = $rec['control'];
            $out[] = [
                'control_id' => $c['id'], 'category' => $c['category'], 'title' => $c['title'], 'description' => $c['description'],
                'mandatory' => $c['mandatory'], 'expected_evidence' => $c['expected_evidence'], 'owner_role' => $c['owner_role'],
                'verification_required' => $c['verification_required'], 'risk_trigger' => $c['risk_trigger'],
                'source' => $rec['source'], 'why_recommended' => $rec['reason'],
            ];
        }
        return Response::json(['data' => $out]);
    }

    public function startControls(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $n = $this->app->controlService()->startControls($user, (int) $e['id']);
        return Response::json(['created' => $n, 'entity' => self::entityJson($this->app->entities()->find((int) $e['id']))]);
    }

    public function recordControl(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $a = $this->app->controlService()->record($user, (int) $e['id'], $request->intParam('controlId'), $request->body);
        return Response::json(['assessment' => $a, 'residual' => $this->app->assessments()->residualExplanation($this->app->entities()->find((int) $e['id']))]);
    }

    public function verify(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $this->app->controlService()->verify($user, (int) $e['id'], $request->intParam('controlId'), $request->input('verified', true) !== false);
        return Response::json(['residual' => $this->app->assessments()->residualExplanation($this->app->entities()->find((int) $e['id']))]);
    }

    /** Multipart: control_id + evidence (file), optional evidence_owner / evidence_date / expiry_date. */
    public function evidence(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $file = $request->file('evidence');
        if ($file === null) {
            throw new ValidationException('Attach the evidence file in the "evidence" field.', ['evidence' => 'Required.']);
        }
        $cid = (int) $request->input('control_id', 0);
        $in = array_intersect_key($request->body, array_flip(['evidence_owner', 'evidence_date', 'expiry_date']));
        $a = $this->app->controlService()->uploadEvidence($user, (int) $e['id'], $cid, $file, $in);
        return Response::json(['assessment' => array_diff_key($a, ['evidence_path' => true]) + ['has_evidence' => true]], 201);
    }

    public function confirmNegligible(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $confirm = $request->input('confirm', true);
        $r = $this->app->assessments()->confirmNegligible($user, (int) $e['id'], (string) $request->input('justification', ''), $confirm !== false && $confirm !== 'false' && $confirm !== '0');
        return Response::json(['residual' => $r->toArray()]);
    }

    public function residual(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $x = $this->app->assessments()->residualExplanation($e);
        if ($x === null) {
            return Response::jsonError('not_scored', 'Inherent risk has not been scored yet.', 409);
        }
        return Response::json($x);
    }

    public function calculateResidual(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $this->app->assessments()->calculateResidual($user, (int) $e['id']);
        $fresh = $this->app->entities()->find((int) $e['id']);
        return Response::json(['residual' => $this->app->assessments()->residualExplanation($fresh), 'entity' => self::entityJson($fresh)]);
    }

    public function submit(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $this->app->approvals()->submit($user, (int) $e['id']);
        $fresh = $this->app->entities()->find((int) $e['id']);
        return Response::json(['entity' => self::entityJson($fresh), 'approval_round' => $this->roundJson($fresh)]);
    }

    public function approve(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $outcome = $this->app->approvals()->decide(
            $user,
            (int) $e['id'],
            (string) $request->input('decision', ''),
            (string) $request->input('justification', ''),
            $request->input('accepted_until'),
        );
        $fresh = $this->app->entities()->find((int) $e['id']);
        return Response::json(['outcome' => $outcome, 'entity' => self::entityJson($fresh), 'approval_round' => $this->roundJson($fresh)]);
    }

    public function reassess(Request $request, PortalUser $user): Response
    {
        $e = $this->load($user, $request);
        $this->app->assessments()->startReassessment($user, (int) $e['id']);
        return Response::json(['entity' => self::entityJson($this->app->entities()->find((int) $e['id']))]);
    }

    public function createAction(Request $request, PortalUser $user): Response
    {
        $id = $this->app->remediation()->create($user, $request->body);
        return Response::json($this->app->remediation()->decorate($this->app->remediationRepo()->find($id)), 201);
    }

    public function listActions(Request $request, PortalUser $user): Response
    {
        $f = array_filter(array_intersect_key($request->query, array_flip(['risk_entity_id', 'status', 'department_id', 'entity_type', 'owner', 'priority'])), fn ($v) => $v !== '');
        $rows = $this->app->remediation()->list($user, $f);
        return Response::json(['data' => $rows, 'summary' => \App\Services\RemediationService::summary($rows)]);
    }

    public function updateAction(Request $request, PortalUser $user): Response
    {
        $id = $request->intParam('id');
        $this->app->remediation()->update($user, $id, $request->body);
        return Response::json($this->app->remediation()->decorate($this->app->remediationRepo()->find($id)));
    }
}
