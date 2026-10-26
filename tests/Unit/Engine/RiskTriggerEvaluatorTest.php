<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\EntityType;
use App\Domain\Rating;
use App\Engine\RiskProfile;
use App\Engine\RiskTriggerEvaluator;
use PHPUnit\Framework\TestCase;

final class RiskTriggerEvaluatorTest extends TestCase
{
    private RiskTriggerEvaluator $eval;
    private RiskProfile $profile;

    protected function setUp(): void
    {
        $this->eval = new RiskTriggerEvaluator();
        $this->profile = new RiskProfile(
            EntityType::ProcessingActivity,
            ['data_classification' => 4, 'cross_border_transfer' => 2, 'automated_decision_making' => 4],
            true,
            Rating::High,
            ['contact', 'health'],
        );
    }

    public function testComparisons(): void
    {
        self::assertTrue($this->eval->matches('data_classification>=4', $this->profile));
        self::assertFalse($this->eval->matches('data_classification>4', $this->profile));
        self::assertFalse($this->eval->matches('cross_border_transfer>=3', $this->profile));
        self::assertTrue($this->eval->matches('involves_data_sharing=true', $this->profile));
        self::assertTrue($this->eval->matches('inherent_rating>=medium', $this->profile));
        self::assertTrue($this->eval->matches('always', $this->profile));
        self::assertTrue($this->eval->matches('data_type~health', $this->profile));
        self::assertFalse($this->eval->matches('data_type~biometric', $this->profile));
        self::assertTrue($this->eval->matches('entity_type=processing_activity', $this->profile));
    }

    public function testAndOr(): void
    {
        self::assertTrue($this->eval->matches('cross_border_transfer>=3 | automated_decision_making>=4', $this->profile));
        self::assertFalse($this->eval->matches('data_classification>=4 & cross_border_transfer>=3', $this->profile));
        self::assertTrue($this->eval->matches('data_classification>=4 & involves_data_sharing=true', $this->profile));
    }

    public function testInactiveFactorNeverMatches(): void
    {
        self::assertFalse($this->eval->matches('vendor_risk>=1', $this->profile));
    }

    public function testValidate(): void
    {
        self::assertNull($this->eval->validate('data_classification>=4 | involves_data_sharing=true'));
        self::assertNotNull($this->eval->validate('data_classification>=9'));
        self::assertNotNull($this->eval->validate('colour=blue'));
        self::assertNotNull($this->eval->validate(''));
    }

    public function testDescribeIsPlainLanguage(): void
    {
        self::assertSame(
            'Data classification / sensitivity score is at least 4, or the activity involves sharing data',
            $this->eval->describe('data_classification>=4|involves_data_sharing=true'),
        );
    }
}
