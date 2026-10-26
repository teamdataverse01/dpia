<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\App;
use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Services\FactorCatalog;
use App\Support\ValidationException;

/** Control library, templates (fast path), admin configuration and audit log endpoints. */
final class LibraryApi
{
    public function __construct(private readonly App $app)
    {
    }

    /** Current user, roles and the CSRF token to send as X-CSRF-Token on state-changing requests. */
    public function session(Request $request, PortalUser $user): Response
    {
        return Response::json([
            'user' => ['id' => $user->id, 'name' => $user->displayName, 'roles' => array_map(fn ($r) => $r->value, $user->roles)],
            'csrf_token' => Csrf::token(),
        ]);
    }

    public function controls(Request $request, PortalUser $user): Response
    {
        $all = $request->query('include_inactive') === '1' && $this->app->authz()->can($user, P::AdminConfig);
        $rows = $this->app->controls()->all(!$all);
        foreach ($rows as &$c) {
            $c['trigger_description'] = $this->app->triggers()->describe($c['risk_trigger']);
        }
        unset($c);
        return Response::json(['data' => $rows]);
    }

    public function createControl(Request $request, PortalUser $user): Response
    {
        $id = $this->app->admin()->saveControl($user, null, $request->body);
        return Response::json($this->app->controls()->find($id), 201);
    }

    public function updateControl(Request $request, PortalUser $user): Response
    {
        $id = $request->intParam('id');
        $existing = $this->app->controls()->find($id);
        if ($existing === null) {
            throw new \App\Support\NotFoundException('Control not found.');
        }
        $this->app->admin()->saveControl($user, $id, $request->body + $existing);
        return Response::json($this->app->controls()->find($id));
    }

    public function templates(Request $request, PortalUser $user): Response
    {
        $dept = (int) $request->query('department_id', 0) ?: null;
        return Response::json(['data' => $this->app->templates()->search($dept, (string) $request->query('q', ''))]);
    }

    /** Match by activity_name + department_id, and/or by factor signatures (data_type[], data_subject_type, …). */
    public function matchTemplates(Request $request, PortalUser $user): Response
    {
        $dept = (int) $request->query('department_id', 0) ?: null;
        $name = $request->query('activity_name');
        $factors = array_intersect_key($request->query, array_flip(['data_type', 'data_subject_type', 'nature_of_processing', 'automated_decision_making', 'cross_border_transfer', 'third_party_transfer']));
        if (isset($factors['data_type']) && is_string($factors['data_type'])) {
            $factors['data_type'] = explode(',', $factors['data_type']);
        }
        $matches = $this->app->templates()->match($dept, is_string($name) ? $name : null, $factors ?: null);
        return Response::json(['data' => array_map(fn ($m) => $m->toArray(), $matches)]);
    }

    /** Save-as-template from an approved DPIA: { risk_entity_id, activity_name? }. */
    public function saveTemplate(Request $request, PortalUser $user): Response
    {
        $eid = (int) $request->input('risk_entity_id', 0);
        $this->app->assessments()->getForUser($user, $eid);
        $id = $this->app->templates()->saveFromEntity($user, $eid, $request->input('activity_name'));
        return Response::json($this->app->templatesRepo()->find($id), 201);
    }

    /** { template_id, mode: accept|customise, confirm_stale? } */
    public function applyTemplate(Request $request, PortalUser $user): Response
    {
        $id = $request->intParam('id');
        $this->app->assessments()->getForUser($user, $id);
        $r = $this->app->templates()->apply(
            $user,
            $id,
            (int) $request->input('template_id', 0),
            (string) $request->input('mode', ''),
            FactorCatalog::parseBool($request->input('confirm_stale', false)),
        );
        return Response::json([
            'mode' => $r['mode'],
            'inherent' => $r['inherent']->toArray(),
            'entity' => RiskEntityApi::entityJson($this->app->entities()->find($id)),
        ]);
    }

    public function getConfig(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, P::AdminConfig);
        $c = $this->app->configs()->active();
        return Response::json(['version' => $c->version, 'config' => $c->toArray(), 'history' => $this->app->configs()->history()]);
    }

    /** Creates a NEW version; never edits in place. Body: full config object (+ optional change_note). */
    public function putConfig(Request $request, PortalUser $user): Response
    {
        $body = $request->body;
        $note = isset($body['change_note']) ? (string) $body['change_note'] : null;
        unset($body['change_note']);
        $v = $this->app->admin()->saveConfig($user, $body, $note);
        return Response::json(['version' => $v, 'config' => $this->app->configs()->active()->toArray()]);
    }

    public function getFactorOptions(Request $request, PortalUser $user): Response
    {
        return Response::json(['data' => $this->app->refs()->factorOptions()]);
    }

    /** Body: { options: [ { id, label, help_text, score, sort_order } ] } — score changes create a new config version. */
    public function putFactorOptions(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, P::AdminConfig);
        $opts = $request->input('options');
        if (!is_array($opts)) {
            throw new ValidationException('Send { "options": [ ... ] }.', ['options' => 'Required.']);
        }
        $this->app->db()->transaction(function () use ($opts, $user) {
            foreach ($opts as $o) {
                $this->app->admin()->updateFactorOption($user, (int) ($o['id'] ?? 0), $o);
            }
        });
        return Response::json(['data' => $this->app->refs()->factorOptions(), 'config_version' => $this->app->configs()->active()->version]);
    }

    public function auditLog(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, P::ReadAll);
        $f = array_filter(array_intersect_key($request->query, array_flip(['entity_table', 'entity_id', 'actor', 'action', 'from', 'to'])), fn ($v) => $v !== '');
        $limit = (int) $request->query('limit', 200);
        $offset = (int) $request->query('offset', 0);
        return Response::json(['data' => $this->app->auditRepo()->list($f, $limit, $offset)]);
    }
}
