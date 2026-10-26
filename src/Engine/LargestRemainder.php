<?php

declare(strict_types=1);

namespace App\Engine;

/** Integer percentages that always sum to exactly 100 (PRD 11.1). */
final class LargestRemainder
{
    /**
     * @param array<string,int> $counts
     * @return array<string,int> same keys, whole percentages summing to 100 (all zero when total is 0)
     */
    public static function percentages(array $counts): array
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return array_map(fn () => 0, $counts);
        }
        $floors = [];
        $remainders = [];
        foreach ($counts as $k => $c) {
            $exact = $c * 100 / $total;
            $floors[$k] = (int) floor($exact);
            $remainders[$k] = $exact - $floors[$k];
        }
        $left = 100 - array_sum($floors);
        // Largest remainder first; ties broken by larger count, then by original key order.
        $order = array_keys($counts);
        usort($order, function ($a, $b) use ($remainders, $counts, $order) {
            return [$remainders[$b], $counts[$b], array_search($a, $order, true)]
                <=> [$remainders[$a], $counts[$a], array_search($b, $order, true)];
        });
        for ($i = 0; $i < $left; $i++) {
            $floors[$order[$i]]++;
        }
        return $floors;
    }
}
