<?php

declare(strict_types=1);

namespace App\Engine;

/** PRD 8.2 / 9.4 — conversions between the vendor 0–100 residual score and the DPIA 1–5 vendor-risk factor. */
final class VendorRiskMapper
{
    /** 0–20 → 1 · 21–40 → 2 · 41–60 → 3 · 61–80 → 4 · 81–100 → 5 */
    public static function factorScore(int $score100): int
    {
        $s = max(0, min(100, $score100));
        return match (true) {
            $s <= 20 => 1,
            $s <= 40 => 2,
            $s <= 60 => 3,
            $s <= 80 => 4,
            default => 5,
        };
    }

    /** residual_score_100 = round((residual_score − 1) / 4 × 100), clamped to 0–100. */
    public static function to100(float $score1to5): int
    {
        return max(0, min(100, (int) round(($score1to5 - 1) / 4 * 100)));
    }

    public static function bandLabel(int $factorScore): string
    {
        return match ($factorScore) {
            1 => '0–20',
            2 => '21–40',
            3 => '41–60',
            4 => '61–80',
            default => '81–100',
        };
    }
}
