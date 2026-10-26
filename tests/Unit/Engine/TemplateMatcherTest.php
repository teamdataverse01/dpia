<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\TemplateCandidate;
use App\Engine\TemplateMatcher;
use PHPUnit\Framework\TestCase;

final class TemplateMatcherTest extends TestCase
{
    private TemplateMatcher $m;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->m = new TemplateMatcher();
        $this->today = new \DateTimeImmutable('2026-10-18');
    }

    private function jamb(int $version = 1, string $reviewed = '2026-06-01'): TemplateCandidate
    {
        return new TemplateCandidate(
            1, 1, 'Admissions', 'Admissions — JAMB data intake',
            TemplateMatcher::dataTypeSignature(['identity', 'contact', 'academic']),
            'prospective_students_minors',
            TemplateMatcher::characteristicsSignature('profiling_monitoring', 'largely_automated', 'adequate_safeguards', 'several_external'),
            $version, $reviewed,
        );
    }

    public function testExactNameAndDepartmentMatchIsCaseAndDashInsensitive(): void
    {
        $r = $this->m->match([$this->jamb()], 1, '  admissions - jamb  data intake', null, null, null, Fixtures::config(), $this->today);
        self::assertCount(1, $r);
        self::assertSame('name_department', $r[0]->matchType);
        self::assertStringContainsString('exactly match', $r[0]->reason);
        self::assertFalse($r[0]->stale);
    }

    public function testWrongDepartmentDoesNotMatchByName(): void
    {
        self::assertSame([], $this->m->match([$this->jamb()], 2, 'Admissions — JAMB data intake', null, null, null, Fixtures::config(), $this->today));
    }

    public function testSignatureMatchRequiresAllThreeByDefault(): void
    {
        $dt = TemplateMatcher::dataTypeSignature(['academic', 'identity', 'contact']); // order-insensitive
        $ch = TemplateMatcher::characteristicsSignature('profiling_monitoring', 'largely_automated', 'adequate_safeguards', 'several_external');

        $all = $this->m->match([$this->jamb()], null, null, $dt, 'prospective_students_minors', $ch, Fixtures::config(), $this->today);
        self::assertCount(1, $all);
        self::assertSame('signature', $all[0]->matchType);
        self::assertSame(['data types', 'data subjects', 'processing characteristics'], $all[0]->matchedSignatures);

        $two = $this->m->match([$this->jamb()], null, null, $dt, 'staff', $ch, Fixtures::config(), $this->today);
        self::assertSame([], $two);

        $lenient = Fixtures::config(['template_signature_threshold' => 2], 2);
        self::assertCount(1, $this->m->match([$this->jamb(2)], null, null, $dt, 'staff', $ch, $lenient, $this->today));
    }

    public function testStaleWhenFrameworkVersionDiffers(): void
    {
        $r = $this->m->match([$this->jamb(1)], 1, 'Admissions — JAMB data intake', null, null, null, Fixtures::config([], 2), $this->today);
        self::assertTrue($r[0]->stale);
        self::assertStringContainsString('version 1', $r[0]->staleReasons[0]);
    }

    public function testStaleWhenNotReviewedWithinLimit(): void
    {
        [$stale, $why] = $this->m->staleness($this->jamb(1, '2025-10-17'), Fixtures::config(), $this->today);
        self::assertTrue($stale);
        self::assertStringContainsString('12 months', $why[0]);

        [$fresh] = $this->m->staleness($this->jamb(1, '2025-10-18'), Fixtures::config(), $this->today);
        self::assertFalse($fresh);
    }
}
