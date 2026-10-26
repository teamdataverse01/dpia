<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Http\Request;
use App\Http\Response;
use App\Services\FactorCatalog;
use App\Support\ValidationException;

/** PRD 10 — DPIA fast path screens. */
final class TemplateController extends BaseController
{
    /** "New DPIA": search for a pre-scored recurring activity before starting from scratch. */
    public function fastPath(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, P::Create);
        $dept = (int) $request->query('department_id', 0) ?: null;
        $q = trim((string) $request->query('q', ''));
        $searched = $request->query('searched') !== null;
        $results = $searched ? $this->app->templates()->search($dept, $q) : [];
        $exact = $searched && $dept !== null && $q !== '' ? $this->app->templates()->match($dept, $q, null) : [];
        return $this->render('templates/fast_path', [
            'title' => 'New DPIA',
            'departments' => $this->app->refs()->departments(),
            'dept' => $dept,
            'q' => $q,
            'searched' => $searched,
            'results' => $results,
            'exact' => $exact,
        ]);
    }

    public function preview(Request $request, PortalUser $user, array $errors = [], array $old = []): Response
    {
        $p = $this->app->templates()->preview($request->intParam('id'));
        $p['tpl'] = $p['template']; // "$template" is reserved inside View::partial()
        unset($p['template']);
        return $this->render('templates/preview', $p + [
            'title' => $p['tpl']['activity_name'],
            'canStart' => $this->app->authz()->can($user, P::Create),
            'errors' => $errors,
            'old' => $old,
            'optionLabel' => fn (string $f, ?string $k) => $this->app->catalog()->label($f, $k),
            'why' => (string) $request->query('why', ''),
        ], $errors ? 422 : 200);
    }

    public function start(Request $request, PortalUser $user): Response
    {
        $tid = $request->intParam('id');
        $mode = (string) $request->input('mode', '');
        try {
            $id = $this->app->templates()->startFromTemplate(
                $user,
                $tid,
                $mode,
                [
                    'name' => (string) $request->input('name', ''),
                    'purpose' => (string) $request->input('purpose', ''),
                    'description' => (string) $request->input('description', ''),
                    'retention_period' => (string) $request->input('retention_period', ''),
                ],
                FactorCatalog::parseBool($request->input('confirm_stale', false)),
            );
        } catch (ValidationException $ex) {
            return $this->preview($request, $user, $ex->fields ?: ['form' => $ex->getMessage()], $request->body);
        }
        if ($mode === 'accept') {
            $this->flash('success', 'Template accepted: factors, score and standard controls are prefilled. Record the status of each control.');
            return $this->redirect("/assessments/{$id}/controls");
        }
        $this->flash('success', 'Template values prefilled. Change anything that differs for this activity — the score updates as you go.');
        return $this->redirect("/assessments/{$id}/factors");
    }

    public function index(Request $request, PortalUser $user): Response
    {
        if (!$this->app->authz()->can($user, P::Create) && !$this->app->authz()->can($user, P::SaveTemplate) && !$this->app->authz()->can($user, P::ReadAll)) {
            throw new \App\Support\ForbiddenException();
        }
        $rescored = $_SESSION['_rescored'] ?? null; // results of the last bulk re-score, shown once
        unset($_SESSION['_rescored']);
        return $this->render('templates/index', [
            'title' => 'Activity templates',
            'templates' => $this->app->templates()->search(null, null),
            'canRescore' => $this->app->authz()->can($user, P::AdminConfig),
            'rescored' => $rescored,
            'currentVersion' => $this->app->configs()->active()->version,
        ]);
    }

    public function rescore(Request $request, PortalUser $user): Response
    {
        $_SESSION['_rescored'] = $this->app->templates()->rescoreAll($user);
        $this->flash('success', 'All templates re-scored with the current framework.');
        return $this->redirect('/templates');
    }

    public function saveFromEntity(Request $request, PortalUser $user): Response
    {
        $id = $request->intParam('id');
        $this->app->assessments()->getForUser($user, $id);
        $tid = $this->app->templates()->saveFromEntity($user, $id, (string) $request->input('activity_name', ''));
        $this->flash('success', 'Saved as a reusable template.');
        return $this->redirect("/templates/{$tid}");
    }
}
