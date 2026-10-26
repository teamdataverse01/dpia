<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\InvalidConfigException;
use App\Engine\ScoringConfig;
use PHPUnit\Framework\TestCase;

final class ScoringConfigTest extends TestCase
{
    public function testDefaultsAreValid(): void
    {
        self::assertSame([], ScoringConfig::validate(ScoringConfig::defaults()));
        $c = ScoringConfig::fromArray(ScoringConfig::defaults(), 1);
        self::assertSame(1, $c->version);
        self::assertSame(70, $c->clearanceMinEffectiveness);
    }

    public function testWeightsNotSummingTo100AreRejected(): void
    {
        $data = ScoringConfig::defaults();
        $data['weights']['data_volume'] = 20; // total 105
        $errors = ScoringConfig::validate($data);
        self::assertArrayHasKey('weights', $errors);
        self::assertStringContainsString('105', $errors['weights']);

        $this->expectException(InvalidConfigException::class);
        ScoringConfig::fromArray($data, 2);
    }

    public function testWeightsBelow100AreRejected(): void
    {
        $data = ScoringConfig::defaults();
        $data['weights']['legal_basis'] = 0; // total 95
        self::assertArrayHasKey('weights', ScoringConfig::validate($data));
    }

    public function testMissingWeightIsRejected(): void
    {
        $data = ScoringConfig::defaults();
        unset($data['weights']['legal_basis']);
        self::assertArrayHasKey('weights.legal_basis', ScoringConfig::validate($data));
    }

    public function testDecimalWeightsSummingTo100AreAccepted(): void
    {
        $data = ScoringConfig::defaults();
        $data['weights']['data_classification'] = 17.5;
        $data['weights']['legal_basis'] = 7.5;
        self::assertSame([], ScoringConfig::validate($data));
    }

    public function testOverlappingBandsAreRejected(): void
    {
        $data = ScoringConfig::defaults();
        $data['bands'] = ['low_max' => 3.6, 'medium_max' => 3.5];
        self::assertArrayHasKey('bands', ScoringConfig::validate($data));
    }

    public function testTechnologyMustHaveHighestReductionWeight(): void
    {
        $data = ScoringConfig::defaults();
        $data['reduction_weights'] = ['policy' => 0.40, 'process' => 0.25, 'technology' => 0.15];
        self::assertArrayHasKey('reduction_weights', ScoringConfig::validate($data));
    }

    public function testReductionWeightsMayNotExceedOne(): void
    {
        $data = ScoringConfig::defaults();
        $data['reduction_weights'] = ['policy' => 0.3, 'process' => 0.3, 'technology' => 0.5];
        self::assertArrayHasKey('reduction_weights', ScoringConfig::validate($data));
    }

    public function testUnknownApprovalRoleIsRejected(): void
    {
        $data = ScoringConfig::defaults();
        $data['approval_routing']['high'] = ['chancellor'];
        self::assertArrayHasKey('approval_routing.high', ScoringConfig::validate($data));
    }

    public function testRoundTrip(): void
    {
        $c = ScoringConfig::fromArray(ScoringConfig::defaults(), 3);
        $again = ScoringConfig::fromArray($c->toArray(), 3);
        self::assertEquals($c, $again);
    }
}
