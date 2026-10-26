<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\ControlCategory as C;
use App\Domain\ControlStatus as S;
use App\Engine\ClearanceEvaluator;
use App\Engine\ControlAssessmentInput;
use PHPUnit\Framework\TestCase;

final class ClearanceEvaluatorTest extends TestCase
{
    private ClearanceEvaluator $eval;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->eval = new ClearanceEvaluator();
        $this->today = new \DateTimeImmutable('2026-10-18');
    }

    private function control(
        int $id,
        C $cat,
        bool $mandatory = true,
        S $status = S::Implemented,
        ?int $eff = 80,
        bool $evidence = true,
        ?string $expiry = null,
        bool $verificationRequired = false,
        bool $verified = false,
    ): ControlAssessmentInput {
        return new ControlAssessmentInput($id, "Control {$id}", $cat, $mandatory, $status, $eff, $evidence, $expiry, $verificationRequired, $verified);
    }

    public function testAllMandatoryMeetingCriteriaClearsCategory(): void
    {
        $r = $this->eval->evaluate([
            $this->control(1, C::Technology),
            $this->control(2, C::Technology),
            $this->control(3, C::Process),
        ], Fixtures::config(), $this->today);

        self::assertSame([C::Process, C::Technology], $r->cleared);
        self::assertFalse($r->isCleared(C::Policy));
        self::assertNotEmpty($r->reasons['policy']);
    }

    public function testOneFailingMandatoryControlBlocksCategory(): void
    {
        $r = $this->eval->evaluate([
            $this->control(1, C::Technology),
            $this->control(2, C::Technology, status: S::InProgress),
        ], Fixtures::config(), $this->today);

        self::assertFalse($r->isCleared(C::Technology));
        self::assertStringContainsString('Control 2', $r->reasons['technology'][0]);
        self::assertArrayHasKey(2, $r->controlGaps);
    }

    public function testNonMandatoryControlsDoNotBlockClearance(): void
    {
        $r = $this->eval->evaluate([
            $this->control(1, C::Policy),
            $this->control(2, C::Policy, mandatory: false, status: S::NotStarted, eff: null, evidence: false),
        ], Fixtures::config(), $this->today);

        self::assertTrue($r->isCleared(C::Policy));
    }

    public function testCategoryWithOnlyNonMandatoryControlsClearsWhenOneMeetsCriteria(): void
    {
        $met = $this->eval->evaluate([$this->control(1, C::Process, mandatory: false)], Fixtures::config(), $this->today);
        self::assertTrue($met->isCleared(C::Process));

        $unmet = $this->eval->evaluate([$this->control(1, C::Process, mandatory: false, eff: 50)], Fixtures::config(), $this->today);
        self::assertFalse($unmet->isCleared(C::Process));
    }

    public function testEmptyCategoryIsNeverCleared(): void
    {
        $r = $this->eval->evaluate([], Fixtures::config(), $this->today);
        self::assertSame([], $r->cleared);
    }

    public function testEffectivenessThresholdIsInclusiveAndConfigurable(): void
    {
        self::assertTrue($this->eval->evaluate([$this->control(1, C::Policy, eff: 70)], Fixtures::config(), $this->today)->isCleared(C::Policy));
        self::assertFalse($this->eval->evaluate([$this->control(1, C::Policy, eff: 69)], Fixtures::config(), $this->today)->isCleared(C::Policy));

        $strict = Fixtures::config(['clearance_min_effectiveness' => 90], 2);
        self::assertFalse($this->eval->evaluate([$this->control(1, C::Policy, eff: 80)], $strict, $this->today)->isCleared(C::Policy));
    }

    public function testMissingOrExpiredEvidenceBlocks(): void
    {
        self::assertFalse($this->eval->evaluate([$this->control(1, C::Policy, evidence: false)], Fixtures::config(), $this->today)->isCleared(C::Policy));
        self::assertFalse($this->eval->evaluate([$this->control(1, C::Policy, expiry: '2026-10-17')], Fixtures::config(), $this->today)->isCleared(C::Policy));
        self::assertTrue($this->eval->evaluate([$this->control(1, C::Policy, expiry: '2026-10-18')], Fixtures::config(), $this->today)->isCleared(C::Policy));
    }

    public function testVerificationRequiredNeedsReviewerVerification(): void
    {
        self::assertFalse($this->eval->evaluate([$this->control(1, C::Technology, verificationRequired: true)], Fixtures::config(), $this->today)->isCleared(C::Technology));
        self::assertTrue($this->eval->evaluate([$this->control(1, C::Technology, verificationRequired: true, verified: true)], Fixtures::config(), $this->today)->isCleared(C::Technology));
    }

    public function testNotApplicableMandatoryControlDoesNotSatisfyClearance(): void
    {
        $r = $this->eval->evaluate([$this->control(1, C::Policy, status: S::NotApplicable)], Fixtures::config(), $this->today);
        self::assertFalse($r->isCleared(C::Policy));
    }
}
