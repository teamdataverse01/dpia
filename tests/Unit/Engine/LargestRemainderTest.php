<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\LargestRemainder;
use App\Engine\VendorRiskMapper;
use PHPUnit\Framework\TestCase;

final class LargestRemainderTest extends TestCase
{
    public function testSumsTo100(): void
    {
        $p = LargestRemainder::percentages(['high' => 1, 'medium' => 1, 'low' => 1, 'very_low' => 0]);
        self::assertSame(100, array_sum($p));
        self::assertSame(['high' => 34, 'medium' => 33, 'low' => 33, 'very_low' => 0], $p);
    }

    public function testManyRandomDistributionsSumTo100(): void
    {
        mt_srand(42);
        for ($i = 0; $i < 500; $i++) {
            $counts = ['high' => mt_rand(0, 50), 'medium' => mt_rand(0, 50), 'low' => mt_rand(0, 50), 'very_low' => mt_rand(0, 50)];
            if (array_sum($counts) === 0) {
                continue;
            }
            self::assertSame(100, array_sum(LargestRemainder::percentages($counts)));
        }
    }

    public function testEmpty(): void
    {
        self::assertSame(['high' => 0, 'low' => 0], LargestRemainder::percentages(['high' => 0, 'low' => 0]));
    }

    public function testVendorRiskMapping(): void
    {
        self::assertSame(1, VendorRiskMapper::factorScore(0));
        self::assertSame(1, VendorRiskMapper::factorScore(20));
        self::assertSame(2, VendorRiskMapper::factorScore(21));
        self::assertSame(3, VendorRiskMapper::factorScore(60));
        self::assertSame(4, VendorRiskMapper::factorScore(61));
        self::assertSame(4, VendorRiskMapper::factorScore(80));
        self::assertSame(5, VendorRiskMapper::factorScore(81));
        self::assertSame(5, VendorRiskMapper::factorScore(100));
    }
}
