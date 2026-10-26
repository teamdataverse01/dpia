<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Domain\ControlCategory;
use App\Domain\Factor;
use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\Role;
use App\Http\Request;
use App\Http\Response;
use App\Support\ValidationException;

/** Administrator configuration screens and the read-only audit log. */
final class AdminController extends BaseController
{
    private function guard(PortalUser $user): void
    {
        $this->app->authz()->authorize($user, P::AdminConfig);
    }

    public function config(Request $request, PortalUser $user, array $errors = [], ?array $values = null): Response
    {
        $this->guard($user);
        $active = $this->app->configs()->active();
        return $this->render('admin/config', [
            'title' => 'Scoring configuration',
            'active' => $active,
            'values' => $values ?? $active->toArray(),
            'errors' => $errors,
            'history' => $this->app->configs()->history(),
            'factors' => Factor::cases(),
            'categories' => ControlCategory::cases(),
            'roles' => Role::cases(),
        ], $errors ? 422 : 200);
    }

    public function saveConfig(Request $request, PortalUser $user): Response
    {
        $this->guard($user);
        $data = array_intersect_key($request->body, array_flip(['weights', 'bands', 'reduction_weights', 'clearance_min_effectiveness', 'review_interval_months', 'template_staleness_months', 'template_signature_threshold', 'approval_routing']));
        try {
            $v = $this->app->admin()->saveConfig($user, $data, trim((string) $request->input('change_note', '')) ?: null);
        } catch (ValidationException $ex) {
            return $this->config($request, $user, $ex->fields, \App\Services\AdminService::coerceConfig($data));
        }
        $this->flash('success', "Saved as scoring framework version {$v}. Existing assessments keep their version until they are recalculated.");
        return $this->redirect('/admin/config');
    }

    public function factorOptions(Request $request, PortalUser $user): Response
    {
        $this->guard($user);
        return $this->render('admin/factor_options', [
            'title' => 'Factor options',
            'options' => $this->app->refs()->factorOptions(),
            'factors' => Factor::optionBased(),
        ]);
    }

    public function saveFactorOption(Request $request, PortalUser $user): Response
    {
        $this->guard($user);
        try {
            $this->app->admin()->updateFactorOption($user, $request->intParam('id'), $request->body);
            $this->flash('success', 'Option saved.');
        } catch (ValidationException $ex) {
            $this->flash('error', implode(' ', $ex->fields));
        }
        return Response::redirect(url('/admin/factor-options') . '#opt-' . $request->intParam('id'));
    }

    public function createFactorOption(Request $request, PortalUser $user): Response
    {
        $this->guard($user);
        try {
            $this->app->admin()->createFactorOption($user, $request->body);
            $this->flash('success', 'Option added.');
        } catch (ValidationException $ex) {
            $this->flash('error', implode(' ', $ex->fields));
        }
        return $this->redirect('/admin/factor-options');
    }

    public function controls(Request $request, PortalUser $user): Response
    {
        $this->guard($user);
        return $this->render('admin/controls', [
            'title' => 'Control library',
            'controls' => $this->app->controls()->all(false),
            'triggers' => $this->app->triggers(),
        ]);
    }

    public function editControl(Request $request, PortalUser $user, array $errors = [], ?array $old = null): Response
    {
        $this->guard($user);
        $id = $request->intParam('id') ?: null;
        $control = $id === null ? null : $this->app->controls()->find($id);
        if ($id !== null && $control === null) {
            throw new \App\Support\NotFoundException('Control not found.');
        }
        return $this->render('admin/control_form', [
            'title' => $control === null ? 'New control' : 'Edit control',
            'control' => $control,
            'old' => $old ?? ($control ?? ['mandatory' => true, 'active' => true, 'risk_trigger' => 'always']),
            'errors' => $errors,
            'categories' => ControlCategory::cases(),
            'roles' => Role::cases(),
        ], $errors ? 422 : 200);
    }

    public function saveControl(Request $request, PortalUser $user): Response
    {
        $this->guard($user);
        $id = $request->intParam('id') ?: null;
        $in = $request->body + ['mandatory' => '0', 'verification_required' => '0', 'active' => '0'];
        try {
            $this->app->admin()->saveControl($user, $id, $in);
        } catch (ValidationException $ex) {
            return $this->editControl($request, $user, $ex->fields, $in);
        }
        $this->flash('success', 'Control saved.');
        return $this->redirect('/admin/controls');
    }

    public function users(Request $request, PortalUser $user, array $errors = [], array $old = []): Response
    {
        $this->guard($user);
        return $this->render('admin/users', [
            'title' => 'User roles',
            'rows' => $this->app->refs()->allUserRoles(),
            'roles' => Role::cases(),
            'departments' => $this->app->refs()->departments(),
            'errors' => $errors,
            'old' => $old,
        ], $errors ? 422 : 200);
    }

    public function addUserRole(Request $request, PortalUser $user): Response
    {
        $this->guard($user);
        $dept = (int) $request->input('department_id', 0);
        try {
            $this->app->admin()->addUserRole($user, (string) $request->input('portal_user_id', ''), (string) $request->input('role', ''), $dept > 0 ? $dept : null);
        } catch (ValidationException $ex) {
            return $this->users($request, $user, $ex->fields, $request->body);
        }
        $this->flash('success', 'Role added.');
        return $this->redirect('/admin/users');
    }

    public function removeUserRole(Request $request, PortalUser $user): Response
    {
        $this->guard($user);
        $this->app->admin()->removeUserRole($user, $request->intParam('id'));
        $this->flash('success', 'Role removed.');
        return $this->redirect('/admin/users');
    }

    public function audit(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, P::ReadAll);
        $f = [];
        foreach (['entity_table', 'entity_id', 'actor', 'action', 'from', 'to'] as $k) {
            $v = trim((string) $request->query($k, ''));
            if ($v !== '') {
                $f[$k] = $v;
            }
        }
        $page = max(1, (int) $request->query('page', 1));
        return $this->render('admin/audit', [
            'title' => 'Audit log',
            'filters' => $f,
            'rows' => $this->app->auditRepo()->list($f, 100, ($page - 1) * 100),
            'page' => $page,
        ]);
    }
}
