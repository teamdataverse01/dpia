<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Domain\AssessmentStatus;
use App\Domain\Factor;
use App\Domain\Permission;
use App\Domain\Role;
use App\Domain\PortalUser;
use App\Http\Request;
use App\Http\Response;
use App\Services\DashboardService;
use App\Support\ValidationException;

/** Dashboard (PRD 11), the filtered risk list behind it, and the remediation tracker. */
final class DashboardController extends BaseController
{
    /** The landing page: each profile sees its own work (DECISIONS D-36). */
    public function dashboard(Request $request, PortalUser $user): Response
    {
        $home = $this->app->home();
        if ($user->hasRole(Role::Admin)) {
            return $this->board($request, $user, '/dashboard');
        }
        if ($user->hasRole(Role::Reviewer)) {
            return $this->render('home/reviewer', ['title' => 'My review queue', 'evidence' => $home->evidenceToVerify(), 'decisions' => $home->decisionsWaiting($user)]);
        }
        if ($user->hasRole(Role::BusinessOwner)) {
            return $this->render('home/owner', ['title' => 'My work'] + $home->ownerWork($user));
        }
        if ($user->hasRole(Role::Vendor)) {
            return $this->render('home/vendor', ['title' => 'What CU needs from you', 'requests' => $home->vendorRequests($user)]);
        }
        return $this->render('home/none', ['title' => 'No role yet']);
    }

    /** The university-wide picture, for a Reviewer who wants it (the DPO's landing page is this board). */
    public function overview(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, Permission::ViewDashboard);
        return $this->board($request, $user, '/overview');
    }

    private function board(Request $request, PortalUser $user, string $boardPath): Response
    {
        $f = DashboardService::filters($request->query);
        $d = $this->app->dashboard();
        return $this->render('dashboard/index', [
            'title' => 'Privacy risk dashboard',
            'boardPath' => $boardPath,
            'decisions' => $this->app->home()->decisionsWaiting($user),
            'filters' => $f,
            'departments' => $this->app->refs()->departments(),
            'overview' => $d->overview($user, $f),
            'byDept' => $d->byDepartment($user, $f),
            'rem' => $d->remediation($user, $f),
            'stories' => $d->stories($user, $f),
        ]);
    }

    public function risks(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, Permission::BrowseRecords);
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
            'scopeDept' => $this->scopeDepartmentName($user),
            'departments' => $this->departmentsFor($user),
            'statuses' => AssessmentStatus::cases(),
        ]);
    }

    public function remediation(Request $request, PortalUser $user): Response
    {
        $this->app->authz()->authorize($user, Permission::BrowseRecords);
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
            'institutionWide' => $this->app->dashboard()->scope($user)['institution_wide'],
            'scopeDept' => $this->scopeDepartmentName($user),
            'filters' => $f,
            'summary' => \App\Services\RemediationService::summary($all),
            'rows' => array_map(
                fn ($a) => $a + [
                    'owner_name' => $this->app->people()->name($a['owner_portal_user_id']),
                    // may this user remind / reassign? (judged on the linked record, not on the action)
                    'can_manage' => $this->app->authz()->can($user, Permission::ManageRemediation, [
                        'owner_portal_user_id' => $a['entity_owner'], 'entity_type' => $a['entity_type'],
                        'department_id' => $a['department_id'], 'vendor_portal_user_id' => null,
                    ]),
                ],
                $this->app->remediation()->list($user, $f),
            ),
            'people' => $this->app->people()->all(),
            'departments' => $this->departmentsFor($user),
            // hidden when looking at "only my actions": that view is about the user's own to-do list
            'unowned' => isset($f['owner']) ? [] : $this->unownedGaps($user, $f, $all),
        ]);
    }

    /**
     * Gaps nobody has been assigned yet, per record (PRD 11.3 "top recurring control gaps", per owner).
     * Covers records still being worked on, plus decided records whose review is due within 60 days;
     * other approved records are left out because the approver accepted their remaining gaps.
     *
     * @return list<array{entity: array, mandatory: int, optional: int, review_due: bool}>
     */
    private function unownedGaps(PortalUser $user, array $f, array $actions): array
    {
        $assigned = [];
        foreach ($actions as $a) {
            if ($a['control_id'] !== null && in_array($a['display_status'], ['open', 'in_progress', 'overdue'], true)) {
                $assigned[(int) $a['risk_entity_id'] . ':' . (int) $a['control_id']] = true;
            }
        }
        $inProgress = ['scored', 'controls_in_progress', 'residual_calculated', 'pending_approval', 'rejected', 'reassessment_due'];
        $reviewSoon = $this->app->clock()->today()->modify('+60 days')->format('Y-m-d');
        $out = [];
        $entities = $this->app->entities()->list(array_intersect_key($f, array_flip(['entity_type', 'department_id'])), $this->app->dashboard()->scope($user));
        foreach ($entities as $e) {
            $due = $e['next_review_date'] !== null && substr((string) $e['next_review_date'], 0, 10) <= $reviewSoon;
            if (!in_array($e['status'], $inProgress, true) && !($due && in_array($e['status'], ['approved', 'risk_accepted', 'monitoring'], true))) {
                continue;
            }
            $mandatory = 0;
            $optional = 0;
            foreach ($this->app->controlService()->gaps($e) as $g) {
                if (isset($assigned[(int) $e['id'] . ':' . (int) $g['control']['id']])) {
                    continue;
                }
                $g['mandatory_for_clearance'] ? $mandatory++ : $optional++;
            }
            if ($mandatory + $optional > 0) {
                $out[] = ['entity' => $e, 'mandatory' => $mandatory, 'optional' => $optional, 'review_due' => $due && !in_array($e['status'], $inProgress, true)];
            }
        }
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2, 'very_low' => 3];
        usort($out, fn ($x, $y) => [$rank[$x['entity']['residual_rating']] ?? 4, -$x['mandatory'], $x['entity']['name']]
            <=> [$rank[$y['entity']['residual_rating']] ?? 4, -$y['mandatory'], $y['entity']['name']]);
        return $out;
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

    public function remindOwner(Request $request, PortalUser $user): Response
    {
        $id = $request->intParam('id');
        $action = $this->app->remediationRepo()->find($id);
        $sent = $this->app->remediation()->remind($user, $id);
        $name = $this->app->people()->name($action['owner_portal_user_id'] ?? null);
        $this->flash($sent ? 'success' : 'error', $sent ? "Reminder sent to {$name}." : "{$name} has no e-mail address on file, so no reminder could be sent.");
        $back = (string) $request->input('return', '/remediation');
        return Response::redirect(url(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/remediation'));
    }

    /** Name of the department a CU Activity Owner's lists are limited to, or null. */
    private function scopeDepartmentName(PortalUser $user): ?string
    {
        $id = $this->app->authz()->departmentOf($user);
        return $id === null ? null : ($this->app->refs()->department($id)['name'] ?? null);
    }

    /** The department filter only offers what the user may see. */
    private function departmentsFor(PortalUser $user): array
    {
        $id = $this->app->authz()->departmentOf($user);
        $all = $this->app->refs()->departments();
        return $id === null ? $all : array_values(array_filter($all, fn ($d) => $d['id'] === $id));
    }

    public function help(Request $request, PortalUser $user): Response
    {
        return $this->render('help', ['title' => 'Help and glossary']);
    }
}
