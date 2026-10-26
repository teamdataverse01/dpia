<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ControlCategory;
use App\Domain\Factor;

/**
 * Rule-based "Key risk areas" sentences (PRD 11.4). No AI: each story is computed from query results,
 * shown only when the data supports it, and links to the filtered list behind it.
 * Wording lives in config/stories.php.
 */
final class StoryGenerator
{
    /** @param array<string,array<string,string>> $templates */
    public function __construct(private readonly array $templates)
    {
    }

    public static function fromFile(string $path): self
    {
        return new self(require $path);
    }

    /**
     * @param array{
     *   base: array<string,string>,
     *   high_factors: array<string,int>, high_total: int,
     *   departments: list<array{department:string,department_id:?int,count:int,driver:?string}>,
     *   clearance: list<array{cleared_categories:?string,risk_reduced_pct:?string,residual_rating:?string}>,
     *   remediation: array{open_count:int,overdue_count:int,oldest_overdue:?array{gap_description:string,days_overdue:int}},
     *   snapshots: list<array{snapshot_date:string,high:int,total:int}>,
     * } $d
     * @return list<array{type:string,text:string,link:string}>
     */
    public function generate(array $d): array
    {
        $stories = [];
        foreach (['topFactors', 'topDepartments', 'controlEffectiveness', 'remediationHealth', 'trend'] as $fn) {
            $s = $this->$fn($d);
            if ($s !== null) {
                $stories[] = $s;
            }
        }
        return $stories;
    }

    private function fill(string $group, string $key, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }
        return strtr($this->templates[$group][$key], $map);
    }

    private static function link(array $base, array $params, string $path = '/risks'): string
    {
        $q = array_filter($base + $params, fn ($v) => $v !== null && $v !== '');
        return $path . ($q ? '?' . http_build_query($q) : '');
    }

    private function topFactors(array $d): ?array
    {
        $factors = array_filter($d['high_factors'], fn ($n) => $n > 0);
        if ($d['high_total'] === 0 || $factors === []) {
            return null;
        }
        arsort($factors);
        $keys = array_keys($factors);
        $f1 = Factor::from($keys[0]);
        if (count($keys) >= 2) {
            $f2 = Factor::from($keys[1]);
            $text = $this->fill('top_factors', 'two', [
                'factor1' => ucfirst($f1->storyPhrase()), 'factor2' => $f2->storyPhrase(),
                'count' => $factors[$keys[0]], 'total' => $d['high_total'],
            ]);
        } else {
            $text = $this->fill('top_factors', 'one', ['factor1' => ucfirst($f1->storyPhrase()), 'count' => $factors[$keys[0]], 'total' => $d['high_total']]);
        }
        return ['type' => 'top_factors', 'text' => $text, 'link' => self::link($d['base'], ['rating' => 'high', 'factor' => $f1->value])];
    }

    private function topDepartments(array $d): ?array
    {
        $depts = array_values(array_filter($d['departments'], fn ($x) => $x['count'] > 0));
        if ($depts === []) {
            return null;
        }
        usort($depts, fn ($a, $b) => [$b['count'], $a['department']] <=> [$a['count'], $b['department']]);
        $driver = $depts[0]['driver'] !== null ? Factor::from($depts[0]['driver'])->storyPhrase() : 'several factors';
        if (count($depts) >= 2) {
            $text = $this->fill('top_departments', 'two', [
                'dept1' => $depts[0]['department'], 'dept2' => $depts[1]['department'],
                'count1' => $depts[0]['count'], 'count2' => $depts[1]['count'], 'driver' => $driver,
            ]);
        } else {
            $text = $this->fill('top_departments', 'one', ['dept1' => $depts[0]['department'], 'count1' => $depts[0]['count'], 'driver' => $driver]);
        }
        return [
            'type' => 'top_departments',
            'text' => $text,
            'link' => self::link($d['base'], ['department_id' => $depts[0]['department_id'] ?? -1]),
        ];
    }

    private function controlEffectiveness(array $d): ?array
    {
        $sum = [];
        $cnt = [];
        $policyOnlyStuck = 0;
        foreach ($d['clearance'] as $row) {
            $cats = array_filter(explode(',', (string) $row['cleared_categories']));
            foreach ($cats as $c) {
                $sum[$c] = ($sum[$c] ?? 0) + (float) $row['risk_reduced_pct'];
                $cnt[$c] = ($cnt[$c] ?? 0) + 1;
            }
            if ($cats === ['policy'] && in_array($row['residual_rating'], ['high', 'medium'], true)) {
                $policyOnlyStuck++;
            }
        }
        if ($cnt === []) {
            return null;
        }
        $best = null;
        $bestAvg = -1.0;
        foreach (ControlCategory::cases() as $c) { // stable order breaks ties
            if (isset($cnt[$c->value])) {
                $avg = $sum[$c->value] / $cnt[$c->value];
                if ($avg > $bestAvg) {
                    $bestAvg = $avg;
                    $best = $c;
                }
            }
        }
        $text = $this->fill('control_effectiveness', 'best', ['category' => $best->shortLabel(), 'pct' => (int) round($bestAvg)]);
        if ($policyOnlyStuck > 0) {
            $text .= $this->fill('control_effectiveness', 'policy_only', ['count' => $policyOnlyStuck]);
            return ['type' => 'control_effectiveness', 'text' => $text, 'link' => self::link($d['base'], ['cleared' => 'policy'])];
        }
        return ['type' => 'control_effectiveness', 'text' => $text, 'link' => self::link($d['base'], [])];
    }

    private function remediationHealth(array $d): ?array
    {
        $r = $d['remediation'];
        if ($r['overdue_count'] > 0 && $r['oldest_overdue'] !== null) {
            $gap = $r['oldest_overdue']['gap_description'];
            $text = $this->fill('remediation_health', 'overdue', [
                'count' => $r['overdue_count'],
                'actions' => $r['overdue_count'] === 1 ? 'action is' : 'actions are',
                'days' => $r['oldest_overdue']['days_overdue'],
                'oldest' => mb_strlen($gap) > 60 ? mb_substr($gap, 0, 57) . '…' : $gap,
            ]);
            return ['type' => 'remediation_health', 'text' => $text, 'link' => self::link(array_intersect_key($d['base'], array_flip(['entity_type', 'department_id'])), ['status' => 'overdue'], '/remediation')];
        }
        if ($r['open_count'] > 0) {
            $text = $this->fill('remediation_health', 'on_track', ['count' => $r['open_count'], 'actions' => $r['open_count'] === 1 ? 'action is' : 'actions are']);
            return ['type' => 'remediation_health', 'text' => $text, 'link' => self::link(array_intersect_key($d['base'], array_flip(['entity_type', 'department_id'])), ['status' => 'open_all'], '/remediation')];
        }
        return null;
    }

    private function trend(array $d): ?array
    {
        $s = $d['snapshots'];
        if (count($s) < 2) {
            return null;
        }
        $latest = $s[count($s) - 1];
        $target = (new \DateTimeImmutable($latest['snapshot_date']))->modify('-90 days')->format('Y-m-d');
        $ref = $s[0];
        foreach ($s as $snap) {
            if ($snap['snapshot_date'] <= $target) {
                $ref = $snap; // latest snapshot at least ~a quarter old; else the earliest one
            }
        }
        if ($ref['snapshot_date'] === $latest['snapshot_date']) {
            return null;
        }
        $date = (new \DateTimeImmutable($ref['snapshot_date']))->format('j M Y');
        $from = $ref['high'];
        $to = $latest['high'];
        $key = match (true) {
            $from === $to => 'flat',
            $from === 0 => 'from_zero',
            $to < $from => 'down',
            default => 'up',
        };
        $pct = $from === 0 ? 0 : (int) round(abs($to - $from) / $from * 100);
        return [
            'type' => 'trend',
            'text' => $this->fill('trend', $key, ['pct' => $pct, 'date' => $date, 'from' => $from, 'to' => $to]),
            'link' => self::link($d['base'], ['rating' => 'high']),
        ];
    }
}
