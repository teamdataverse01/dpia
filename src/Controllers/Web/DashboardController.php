<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Domain\AssessmentStatus;
use App\Domain\Factor;
use App\Domain\PortalUser;
use App\Http\Request;
use App\Http\Response;
use App\Services\DashboardService;
use App\Support\ValidationException;

/** Dashboard (PRD 11), the filtered risk list behind it, and the remediation tracker. */
final class DashboardController extends BaseController
{
    public function dashboard(Request $request, PortalUser $user): Response
    {
        $f = DashboardService::filters($request->query);
        $d = $this->app->dashboard();
        return $this->render('dashboard/index', [
            'title' => 'Privacy risk dashboard',
            'filters' => $f,
            'departments' => $this->app->refs()->departments(),
            'overview' => $d->overview($user, $f),
            'byDept' => $d->byDepartment($user, $f),
            'rem' => $d->remediation($user, $f),
            'stories' => $d->stories($user, $f),
            'institutionWide' => $d->scope($user)['institution_wide'],
        ]);
    }

    public function risks(Request $request, PortalUser $user): Response
    {
        $f = DashboardService::filters($request->query);
        foreach (['rating', 'status', 'factor', 'cleared', 'q'] as $k) {
            $v = trim((string) $request->query($k, ''));
            if ($v !== '') {
                $f[$k] = $v;
            }
        }
        if (isset($f['factor']) && Factor::tryFrom($f['factor']) === null) {
            unset($f['factor']);
        }
        if (isset($f['rating']) || $request->query('active') === '1') {
            $f['active_only'] = true;
        }
        if ($request->query('mine') === '1') {
            $f['owner'] = $user->id;
        }
        $scope = $this->app->dashboard()->scope($user);
        return $this->render('dashboard/risks', [
            'title' => 'Risks',
            'filters' => $f,
            'rows' => $this->app->entities()->list($f, $scope),
            'departments' => $this->app->refs()->departments(),
            'statuses' => AssessmentStatus::cases(),
        ]);
    }

    public function remediation(Request $request, PortalUser $user): Response
    {
        $f = [];
        foreach (['status', 'department_id', 'entity_type', 'priority'] as $k) {
            $v = trim((string) $request->query($k, ''));
            if ($v !== '') {
                $f[$k] = $v;
            }
        }
        if ($request->query('mine') === '1') {
            $f['owner'] = $user->id;
        }
        $all = $this->app->remediation()->list($user, array_intersect_key($f, array_flip(['department_id', 'entity_type'])));
        return $this->render('dashboard/remediation', [
            'title' => 'Remediation tracker',
            'filters' => $f,
            'summary' => \App\Services\RemediationService::summary($all),
            'rows' => $this->app->remediation()->list($user, $f),
            'departments' => $this->app->refs()->departments(),
        ]);
    }

    public function updateAction(Request $request, PortalUser $user): Response
    {
        $id = $request->intParam('id');
        try {
            $this->app->remediation()->update($user, $id, array_intersect_key($request->body, array_flip(['status', 'completion_pct', 'target_date', 'owner_portal_user_id', 'priority', 'approval_status', 'reviewer_portal_user_id'])));
            $this->flash('success', 'Action updated.');
        } catch (ValidationException $ex) {
            $this->flash('error', implode(' ', $ex->fields) ?: $ex->getMessage());
        }
        $back = (string) $request->input('return', '/remediation');
        return Response::redirect(url(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/remediation'));
    }

    public function help(Request $request, PortalUser $user): Response
    {
        return $this->render('help', ['title' => 'Help and glossary']);
    }
}
