<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\EntityType;
use App\Domain\Factor;
use App\Domain\Rating;
use App\Engine\FactorSelection;
use App\Engine\InherentRiskCalculator;
use App\Engine\ScoringConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InherentRiskCalculatorTest extends TestCase
{
    private InherentRiskCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new InherentRiskCalculator();
    }

    /** PRD 8.5 — mandatory worked example. */
    public function testWorkedExampleStudentApplicationsIs385High(): void
    {
        $r = $this->calc->calculate(EntityType::ProcessingActivity, true, Fixtures::studentApplications(), Fixtures::config());

        self::assertSame(3.85, $r->score);
        self::assertSame(Rating::High, $r->rating);
        self::assertCount(9, $r->breakdown);

        $expected = [
            'data_classification' => 0.80, 'data_volume' => 0.75, 'automated_decision_making' => 0.60,
            'cross_border_transfer' => 0.30, 'vendor_risk' => 0.40, 'third_party_transfer' => 0.40,
            'nature_of_processing' => 0.20, 'data_subject_type' => 0.25, 'legal_basis' => 0.15,
        ];
        foreach ($expected as $factor => $weighted) {
            self::assertEqualsWithDelta($weighted, $r->contribution(Factor::from($factor))->weightedValue, 0.00001, $factor);
        }
    }

    /** PRD 8.6 — mandatory rescale example. */
    public function testRescaleWithoutDataSharingIs383High(): void
    {
        $r = $this->calc->calculate(EntityType::ProcessingActivity, false, Fixtures::studentApplications(false), Fixtures::config());

        self::assertSame(3.83, $r->score);
        self::assertSame(Rating::High, $r->rating);
        self::assertNull($r->contribution(Factor::VendorRisk), 'vendor factor must not appear in the breakdown');
        self::assertSame([Factor::VendorRisk], $r->excludedFactors);
        self::assertEqualsWithDelta(90.0, $r->activeWeightTotalPct, 0.0001);
        self::assertEqualsWithDelta(0.2222, $r->contribution(Factor::DataClassification)->weightUsed, 0.00001);

        $sumWeights = array_sum(array_map(fn ($c) => $c->configuredWeightPct / $r->activeWeightTotalPct, $r->breakdown));
        self::assertEqualsWithDelta(1.0, $sumWeights, 1e-9);
    }

    public function testVendorScoreSuppliedWithoutSharingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->calculate(EntityType::ProcessingActivity, false, Fixtures::studentApplications(true), Fixtures::config());
    }

    public function testMissingFactorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $sel = Fixtures::studentApplications(false);
        array_pop($sel); // drop legal basis
        $this->calc->calculate(EntityType::ProcessingActivity, false, $sel, Fixtures::config());
    }

    public function testVendorAssessmentExcludesLegalBasisAndVendorRiskAndRescales(): void
    {
        // All present except legal basis and vendor risk; supplying legal basis anyway must be ignored.
        $sel = Fixtures::studentApplications(false);
        $r = $this->calc->calculate(EntityType::Vendor, false, $sel, Fixtures::config());

        self::assertNull($r->contribution(Factor::LegalBasis));
        self::assertNull($r->contribution(Factor::VendorRisk));
        self::assertEqualsWithDelta(85.0, $r->activeWeightTotalPct, 0.0001);
        // (3.85 − 0.40 − 0.15) / 0.85 = 3.882… → 3.88
        self::assertSame(3.88, $r->score);
        self::assertSame(Rating::High, $r->rating);
    }

    public function testVendorAssessmentIgnoresDataSharingFlag(): void
    {
        $r = $this->calc->calculate(EntityType::Vendor, true, Fixtures::uniform(3, false, false), Fixtures::config());
        self::assertNull($r->contribution(Factor::VendorRisk));
        self::assertSame(3.0, $r->score);
        self::assertSame(Rating::Medium, $r->rating);
    }

    /** @return iterable<string,array{float,Rating}> */
    public static function bandBoundaries(): iterable
    {
        yield '2.00 Low' => [2.00, Rating::Low];
        yield '2.01 Medium' => [2.01, Rating::Medium];
        yield '3.50 Medium' => [3.50, Rating::Medium];
        yield '3.51 High' => [3.51, Rating::High];
        yield '1.00 Low' => [1.00, Rating::Low];
        yield '5.00 High' => [5.00, Rating::High];
    }

    #[DataProvider('bandBoundaries')]
    public function testBandBoundaries(float $score, Rating $expected): void
    {
        self::assertSame($expected, Fixtures::config()->ratingFor($score));
    }

    public function testBandsAreComparedOnRoundedScore(): void
    {
        // Weights chosen so the raw average is 2.004 → rounds to 2.00 → Low (not Medium).
        $config = ScoringConfig::fromArray(array_replace(ScoringConfig::defaults(), ['weights' => [
            'data_classification' => 0.4, 'data_volume' => 99.6, 'automated_decision_making' => 0,
            'cross_border_transfer' => 0, 'vendor_risk' => 0, 'third_party_transfer' => 0,
            'nature_of_processing' => 0, 'data_subject_type' => 0, 'legal_basis' => 0,
        ]]), 2);
        $sel = Fixtures::uniform(2, true);
        $sel[0] = new FactorSelection(Factor::DataClassification, 'x', 3);
        $r = $this->calc->calculate(EntityType::ProcessingActivity, true, $sel, $config);
        self::assertSame(2.0, $r->score);
        self::assertSame(Rating::Low, $r->rating);
    }

    public function testChangingWeightsInConfigChangesResultWithoutCodeChanges(): void
    {
        $base = $this->calc->calculate(EntityType::ProcessingActivity, true, Fixtures::studentApplications(), Fixtures::config());

        // Move 15 points from data volume (score 5) to cross-border transfer (score 2).
        $config = Fixtures::config(['weights' => ['data_volume' => 0, 'cross_border_transfer' => 30]], 2);
        $changed = $this->calc->calculate(EntityType::ProcessingActivity, true, Fixtures::studentApplications(), $config);

        self::assertSame(3.85, $base->score);
        self::assertSame(3.40, $changed->score); // 3.85 − 0.75 + 0.30
        self::assertSame(Rating::Medium, $changed->rating);
        self::assertSame(2, $changed->configVersion);
    }

    public function testChangingBandsInConfigChangesRating(): void
    {
        $config = Fixtures::config(['bands' => ['low_max' => 2.0, 'medium_max' => 3.9]], 2);
        $r = $this->calc->calculate(EntityType::ProcessingActivity, true, Fixtures::studentApplications(), $config);
        self::assertSame(Rating::Medium, $r->rating);
    }

    public function testExplanationMentionsExcludedVendorFactor(): void
    {
        $r = $this->calc->calculate(EntityType::ProcessingActivity, false, Fixtures::studentApplications(false), Fixtures::config());
        $payload = $r->toArray();
        self::assertSame('vendor_risk', $payload['excluded_factors'][0]['factor']);
        self::assertStringContainsString('Vendor risk score', $payload['explanation']);
    }
}
