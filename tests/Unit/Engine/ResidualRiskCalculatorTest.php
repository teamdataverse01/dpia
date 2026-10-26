<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\ControlCategory as C;
use App\Domain\Rating;
use App\Engine\ResidualRiskCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResidualRiskCalculatorTest extends TestCase
{
    private ResidualRiskCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ResidualRiskCalculator();
    }

    /** PRD 9.5 case 1. */
    public function testHighWithTechnologyAndProcessIsLowWithIndicator(): void
    {
        $r = $this->calc->calculate(Rating::High, 3.85, [C::Technology, C::Process], false, Fixtures::config(), false);

        self::assertSame(Rating::Low, $r->rating);
        self::assertSame(0.65, $r->reductionPct);
        self::assertSame(1.35, $r->residualScore);
        self::assertSame(65.0, $r->riskReducedPct);
        self::assertSame('high_two_plus_with_technology', $r->ruleCode);
        self::assertNull($r->residualScore100);
    }

    /** @return iterable<string,array{Rating,list<C>,Rating}> PRD 9.5 cases 2–6 plus the rest of the table */
    public static function clearanceTable(): iterable
    {
        yield '9.5#2 High, policy+process → Medium' => [Rating::High, [C::Policy, C::Process], Rating::Medium];
        yield '9.5#3 High, technology only → Medium' => [Rating::High, [C::Technology], Rating::Medium];
        yield '9.5#4 High, all three → Low' => [Rating::High, [C::Policy, C::Process, C::Technology], Rating::Low];
        yield '9.5#5 Medium, policy → Low' => [Rating::Medium, [C::Policy], Rating::Low];
        yield '9.5#6 Medium, none → Medium' => [Rating::Medium, [], Rating::Medium];
        yield 'High, none → High' => [Rating::High, [], Rating::High];
        yield 'High, policy only → Medium' => [Rating::High, [C::Policy], Rating::Medium];
        yield 'High, technology+policy → Low' => [Rating::High, [C::Technology, C::Policy], Rating::Low];
        yield 'Medium, technology → Low' => [Rating::Medium, [C::Technology], Rating::Low];
        yield 'Low, none → Low' => [Rating::Low, [], Rating::Low];
        yield 'Low, all → Low' => [Rating::Low, [C::Policy, C::Process, C::Technology], Rating::Low];
    }

    /** @param list<C> $cleared */
    #[DataProvider('clearanceTable')]
    public function testClearanceRules(Rating $inherent, array $cleared, Rating $expected): void
    {
        $r = $this->calc->calculate($inherent, 3.0, $cleared, false, Fixtures::config(), false);
        self::assertSame($expected, $r->rating);
        self::assertSame($expected, $r->computedRating);
        self::assertNotSame('', $r->ruleExplanation);
    }

    /** PRD 9.5 case 7. */
    public function testNegligibleConfirmationOnComputedLowGivesVeryLow(): void
    {
        $r = $this->calc->calculate(Rating::Medium, 2.6, [C::Policy], true, Fixtures::config(), false);
        self::assertSame(Rating::Low, $r->computedRating);
        self::assertSame(Rating::VeryLow, $r->rating);
        self::assertTrue($r->negligibleApplied);
    }

    /** PRD 9.5 case 7 (second half). */
    public function testNegligibleConfirmationIsIgnoredWhenComputedMediumOrHigh(): void
    {
        $medium = $this->calc->calculate(Rating::High, 3.85, [C::Policy], true, Fixtures::config(), false);
        self::assertSame(Rating::Medium, $medium->rating);
        self::assertFalse($medium->negligibleApplied);

        $high = $this->calc->calculate(Rating::High, 3.85, [], true, Fixtures::config(), false);
        self::assertSame(Rating::High, $high->rating);
        self::assertFalse($high->negligibleApplied);
    }

    public function testDuplicateCategoriesAreCountedOnce(): void
    {
        $r = $this->calc->calculate(Rating::High, 3.85, [C::Technology, C::Technology], false, Fixtures::config(), false);
        self::assertSame(Rating::Medium, $r->rating);
        self::assertSame(0.4, $r->reductionPct);
    }

    public function testVendorGetsScoreOn0To100Scale(): void
    {
        $r = $this->calc->calculate(Rating::High, 3.85, [C::Technology, C::Process], false, Fixtures::config(), true);
        // round((1.35 − 1) / 4 × 100) = round(8.75) = 9
        self::assertSame(9, $r->residualScore100);

        $none = $this->calc->calculate(Rating::High, 3.85, [], false, Fixtures::config(), true);
        self::assertSame(71, $none->residualScore100); // (3.85 − 1)/4 × 100 = 71.25
    }

    public function testResidualIndicatorNeverDropsBelowOne(): void
    {
        $r = $this->calc->calculate(Rating::Low, 1.2, [C::Policy, C::Process, C::Technology], false, Fixtures::config(), true);
        self::assertSame(1.0, $r->residualScore);
        self::assertSame(0, $r->residualScore100);
    }

    public function testReductionWeightsComeFromConfig(): void
    {
        $config = Fixtures::config(['reduction_weights' => ['policy' => 0.1, 'process' => 0.2, 'technology' => 0.5]], 2);
        $r = $this->calc->calculate(Rating::High, 4.0, [C::Technology, C::Policy], false, $config, false);
        self::assertSame(0.6, $r->reductionPct);
        self::assertSame(1.6, $r->residualScore);
    }
}
