<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\DashboardService;

/** PRD 15 "Dashboard" acceptance criteria (section 11). */
final class DashboardTest extends IntegrationTestCase
{
    public function testPercentagesSumTo100AndCountsMatchTheList(): void
    {
        $u = $this->user('dev.privacy_dpo');
        foreach (['residual', 'inherent'] as $basis) {
            foreach ([[], ['entity_type' => 'vendor'], ['entity_type' => 'processing_activity'], ['department_id' => '1']] as $q) {
                $f = DashboardService::filters($q + ['basis' => $basis]);
                $o = $this->app()->dashboard()->overview($u, $f);
                self::assertGreaterThan(0, $o['total_active']);
                self::assertSame(100, array_sum(array_column($o['ratings'], 'pct')), "pct sum ({$basis})");
                self::assertSame($o['total_active'], array_sum(array_column($o['ratings'], 'count')));

                $list = $this->app()->entities()->list($f + ['active_only' => true], $this->app()->dashboard()->scope($u));
                self::assertCount($o['total_active'], $list, 'tile total equals the list behind it');
                foreach ($o['ratings'] as $r) {
                    $byRating = $this->app()->entities()->list($f + ['rating' => $r['rating'], 'active_only' => true], $this->app()->dashboard()->scope($u));
                    self::assertCount($r['count'], $byRating, "{$r['rating']} tile equals its list");
                }

                $d = $this->app()->dashboard()->byDepartment($u, $f);
                self::assertSame($o['total_active'], $d['total'], 'department totals equal overview total');
            }
        }
    }

    public function testActiveExcludesDraftsAndRejected(): void
    {
        $o = $this->app()->dashboard()->overview($this->user('dev.privacy_dpo'), DashboardService::filters([]));
        $all = (int) $this->app()->db()->fetchValue("SELECT COUNT(*) FROM risk_entities WHERE status <> 'rejected' AND residual_rating IS NOT NULL");
        self::assertSame($all, $o['total_active']);
        self::assertSame(10, $o['total_active']); // 11 seeded, 1 unscored draft
    }

    public function testOverdueIsComputedFromTargetDate(): void
    {
        $r = $this->app()->dashboard()->remediation($this->user('dev.privacy_dpo'), DashboardService::filters([]));
        self::assertSame(1, $r['summary']['overdue']);
        self::assertSame(1, $r['summary']['completed']);
        foreach ($r['actions'] as $a) {
            $expected = !in_array($a['status'], ['completed', 'cancelled'], true) && substr($a['target_date'], 0, 10) < '2026-10-18';
            self::assertSame($expected, $a['display_status'] === 'overdue', $a['gap_description']);
        }
        // the completed action is never overdue, even if its date passes later
        $completed = array_values(array_filter($r['actions'], fn ($a) => $a['status'] === 'completed'));
        self::assertSame('completed', $completed[0]['display_status']);
    }

    public function testAtLeastThreeStoriesFromRealDataEachWithALink(): void
    {
        $stories = $this->app()->dashboard()->stories($this->user('dev.privacy_dpo'), DashboardService::filters([]));
        self::assertGreaterThanOrEqual(3, count($stories));
        $types = array_column($stories, 'type');
        foreach (['top_factors', 'top_departments', 'control_effectiveness', 'remediation_health', 'trend'] as $t) {
            self::assertContains($t, $types);
        }
        foreach ($stories as $s) {
            self::assertMatchesRegularExpression('#^/(risks|remediation)(\?|$)#', $s['link']);
            self::assertNotSame('', trim($s['text']));
        }
    }

    public function testStoryLinksLeadToTheListTheyDescribe(): void
    {
        $u = $this->user('dev.privacy_dpo');
        $stories = $this->app()->dashboard()->stories($u, DashboardService::filters([]));
        $top = array_values(array_filter($stories, fn ($s) => $s['type'] === 'top_factors'))[0];
        parse_str((string) parse_url($top['link'], PHP_URL_QUERY), $q);
        self::assertSame('high', $q['rating']);
        $list = $this->app()->entities()->list(['rating' => 'high', 'factor' => $q['factor'], 'active_only' => true], $this->app()->dashboard()->scope($u));
        preg_match('/\((\d+) of (\d+)\)/', $top['text'], $m);
        self::assertCount((int) $m[1], $list, 'the sentence count equals the linked list');
    }

    public function testStoriesHideWhenDataDoesNotSupportThem(): void
    {
        $none = $this->app()->dashboard()->stories($this->user('dev.privacy_dpo'), DashboardService::filters(['from' => '2030-01-01']));
        self::assertSame([], $none);
    }

    public function testEachRequestorSeesOnlyTheirOwnRecords(): void
    {
        $ada = $this->app()->dashboard()->overview($this->user('dev.requestor'), DashboardService::filters([]));
        $tunde = $this->app()->dashboard()->overview($this->user('dev.requestor2'), DashboardService::filters([]));
        $dpo = $this->app()->dashboard()->overview($this->user('dev.privacy_dpo'), DashboardService::filters([]));
        self::assertSame(6, $ada['total_active']);
        self::assertSame(4, $tunde['total_active']);
        self::assertSame($dpo['total_active'], $ada['total_active'] + $tunde['total_active']);
        self::assertFalse($this->app()->dashboard()->scope($this->user('dev.requestor'))['institution_wide']);
    }

    public function testNonDashboardRolesSeeOnlyTheirOwnItems(): void
    {
        $vendor = $this->user('dev.vendor');
        $o = $this->app()->dashboard()->overview($vendor, DashboardService::filters([]));
        self::assertSame(1, $o['total_active']); // JAMB only
    }
}
