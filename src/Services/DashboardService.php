<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Permission as P;
use App\Domain\PortalUser;
use App\Domain\Rating;
use App\Engine\LargestRemainder;
use App\Repositories\ConfigRepository;
use App\Repositories\DashboardRepository;
use App\Support\Clock;

/** PRD 11 — institution-wide dashboard data. Users without dashboard rights see only their own items. */
final class DashboardService
{
    public function __construct(
        private readonly DashboardRepository $repo,
        private readonly RemediationService $remediation,
        private readonly ConfigRepository $configs,
        private readonly StoryGenerator $stories,
        private readonly Authorizer $authz,
        private readonly Clock $clock,
    ) {
    }

    /** Normalise query-string filters. */
    public static function filters(array $q): array
    {
        $f = [];
        if (in_array($q['entity_type'] ?? '', ['vendor', 'processing_activity'], true)) {
            $f['entity_type'] = $q['entity_type'];
        }
        if (!empty($q['department_id']) && is_numeric($q['department_id'])) {
            $f['department_id'] = (int) $q['department_id'];
        }
        foreach (['from', 'to'] as $k) {
            if (!empty($q[$k]) && AssessmentService::isDate((string) $q[$k])) {
                $f[$k] = (string) $q[$k];
            }
        }
        $f['basis'] = ($q['basis'] ?? 'residual') === 'inherent' ? 'inherent' : 'residual';
        return $f;
    }

    public function scope(PortalUser $user): array
    {
        if ($this->authz->can($user, P::ViewDashboard)) {
            return ['owner_only' => false, 'department_ids' => null, 'user_id' => $user->id, 'institution_wide' => true];
        }
        return $this->authz->listScope($user) + ['user_id' => $user->id, 'institution_wide' => false];
    }

    public function overview(PortalUser $user, array $f): array
    {
        $counts = $this->repo->ratingCounts($f, $this->scope($user));
        $total = array_sum($counts);
        $pct = LargestRemainder::percentages($counts);
        $tiles = [];
        foreach (Rating::displayOrder() as $r) {
            $tiles[] = ['rating' => $r->value, 'label' => $r->label(), 'count' => $counts[$r->value], 'pct' => $pct[$r->value]];
        }
        return [
            'basis' => $f['basis'],
            'total_active' => $total,
            'ratings' => $tiles,
            'avg_risk_reduced_pct' => $this->repo->averageRiskReduced($f, $this->scope($user)),
        ];
    }

    public function byDepartment(PortalUser $user, array $f): array
    {
        $rows = $this->repo->byDepartment($f, $this->scope($user));
        $depts = [];
        foreach ($rows as $r) {
            $key = $r['department_id'] ?? -1;
            $depts[$key] ??= ['department_id' => $r['department_id'], 'department' => $r['department'], 'total' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'very_low' => 0];
            $depts[$key][$r['rating']] += $r['n'];
            $depts[$key]['total'] += $r['n'];
        }
        $list = array_values($depts);
        usort($list, fn ($a, $b) => [$b['total'], $b['high'], $a['department']] <=> [$a['total'], $a['high'], $b['department']]);
        return ['basis' => $f['basis'], 'departments' => $list, 'total' => array_sum(array_column($list, 'total'))];
    }

    public function remediation(PortalUser $user, array $f): array
    {
        $scope = $this->scope($user);
        $actions = $this->remediation->list($user, array_intersect_key($f, array_flip(['entity_type', 'department_id', 'from', 'to'])));
        $today = $this->clock->today();
        $config = $this->configs->active();
        $expiring = $this->repo->expiring($f, $scope, $today->format('Y-m-d'), $today->modify('+90 days')->format('Y-m-d'));
        foreach ($expiring as &$x) {
            $days = (int) $today->diff(new \DateTimeImmutable(substr((string) $x['expires'], 0, 10)))->days;
            $x['days_left'] = $days;
            $x['window'] = $days <= 30 ? 30 : ($days <= 60 ? 60 : 90);
        }
        unset($x);
        return [
            'summary' => RemediationService::summary($actions),
            'actions' => $actions,
            'pending_approvals' => $this->repo->pendingApprovals($f, $scope),
            'expiring' => $expiring,
            'top_gaps' => $this->repo->topControlGaps($f, $scope, $config->clearanceMinEffectiveness, $today->format('Y-m-d')),
            'avg_risk_reduced_pct' => $this->repo->averageRiskReduced($f, $scope),
        ];
    }

    /** @return list<array{type:string,text:string,link:string}> */
    public function stories(PortalUser $user, array $f): array
    {
        $scope = $this->scope($user);
        $base = array_filter([
            'entity_type' => $f['entity_type'] ?? null,
            'department_id' => $f['department_id'] ?? null,
            'basis' => $f['basis'] === 'inherent' ? 'inherent' : null,
            'from' => $f['from'] ?? null,
            'to' => $f['to'] ?? null,
        ], fn ($v) => $v !== null);

        $highFactors = $this->repo->highScoringFactors($f, $scope, 'high');
        $highTotal = $this->repo->ratingCounts($f, $scope)['high'];

        $deptOpen = [];
        foreach ($this->repo->byDepartment($f, $scope) as $r) {
            if (in_array($r['rating'], ['high', 'medium'], true)) {
                $k = $r['department_id'] ?? -1;
                $deptOpen[$k] ??= ['department' => $r['department'], 'department_id' => $r['department_id'], 'count' => 0, 'driver' => null];
                $deptOpen[$k]['count'] += $r['n'];
            }
        }
        uasort($deptOpen, fn ($a, $b) => [$b['count'], $a['department']] <=> [$a['count'], $b['department']]);
        $deptOpen = array_values($deptOpen);
        foreach (array_slice(array_keys($deptOpen), 0, 2) as $i) {
            $drivers = $this->repo->highScoringFactors(
                array_merge($f, ['department_id' => $deptOpen[$i]['department_id'] ?? -1]),
                $scope,
                null,
                ['high', 'medium'],
            );
            $deptOpen[$i]['driver'] = $drivers === [] ? null : (string) array_key_first($drivers);
        }

        $actions = $this->remediation->list($user, array_intersect_key($f, array_flip(['entity_type', 'department_id', 'from', 'to'])));
        $overdue = array_values(array_filter($actions, fn ($a) => $a['display_status'] === 'overdue'));
        usort($overdue, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);
        $open = array_filter($actions, fn ($a) => in_array($a['display_status'], ['open', 'in_progress', 'overdue'], true));

        return $this->stories->generate([
            'base' => array_map('strval', $base),
            'high_factors' => $highFactors,
            'high_total' => $highTotal,
            'departments' => $deptOpen,
            'clearance' => $this->repo->clearanceStats($f, $scope),
            'remediation' => ['open_count' => count($open), 'overdue_count' => count($overdue), 'oldest_overdue' => $overdue[0] ?? null],
            // snapshots are institution-wide counts over time; they cannot honour a created-date range
            'snapshots' => $scope['institution_wide'] && empty($f['from']) && empty($f['to']) ? $this->repo->snapshotSeries($f) : [],
        ]);
    }
}
