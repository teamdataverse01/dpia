<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DevAuthProvider;

/** End-to-end through the front controller: authentication, RBAC, CSRF, error format, escaping. */
final class HttpTest extends IntegrationTestCase
{
    private const CSRF = 'test-csrf-token';

    private function call(string $method, string $path, ?string $as, array $body = [], bool $csrf = true, array $query = []): Response
    {
        $_SESSION = [];
        if ($as !== null) {
            $_SESSION[DevAuthProvider::SESSION_KEY] = $as;
        }
        $_SESSION['_csrf_token'] = self::CSRF;
        $headers = ['accept' => 'application/json'];
        if ($csrf) {
            $headers['x-csrf-token'] = self::CSRF;
        }
        $req = new Request($method, $path, $query, $body, [], $headers, true);
        return (new Kernel($this->app()))->handle($req);
    }

    private static function json(Response $r): array
    {
        return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testNoPortalSessionIs401(): void
    {
        $r = $this->call('GET', '/api/v1/risk-entities', null);
        self::assertSame(401, $r->status);
        self::assertSame('unauthenticated', self::json($r)['error']['code']);
    }

    public function testCsrfIsRequiredForStateChanges(): void
    {
        $r = $this->call('POST', '/api/v1/risk-entities', 'dev.business_owner', ['entity_type' => 'vendor'], false);
        self::assertSame(419, $r->status);
    }

    public function testCreateFollowsTheMatrix(): void
    {
        $this->inRollback(function () {
            $body = ['entity_type' => 'vendor', 'name' => 'Acme', 'department_id' => 1, 'service_description' => 'Things.'];
            foreach (['dev.reviewer', 'dev.vendor'] as $u) {
                self::assertSame(403, $this->call('POST', '/api/v1/risk-entities', $u, $body)->status, $u);
            }
            $ok = $this->call('POST', '/api/v1/risk-entities', 'dev.business_owner', $body);
            self::assertSame(201, $ok->status);
            self::assertSame('draft', self::json($ok)['status']);
        });
    }

    public function testValidationErrorFormat(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Fee payment processing')['id'];
            $f = self::studentApplicationFactors(false, (int) $this->entityByName('Paystack')['id']);
            $r = $this->call('PUT', "/api/v1/risk-entities/{$id}/factors", 'dev.business_owner', $f);
            self::assertSame(422, $r->status);
            $err = self::json($r)['error'];
            self::assertSame('validation_failed', $err['code']);
            self::assertArrayHasKey('vendor_risk_ref', $err['fields']);
        });
    }

    public function testScoringResponsesCarryAnExplanation(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Fee payment processing')['id'];
            $r = $this->call('POST', "/api/v1/risk-entities/{$id}/score", 'dev.business_owner');
            self::assertSame(200, $r->status);
            $j = self::json($r);
            self::assertNotEmpty($j['inherent']['breakdown']);
            self::assertArrayHasKey('weight_used', $j['inherent']['breakdown'][0]);
            self::assertArrayHasKey('rule_explanation', $j['residual']);
            self::assertArrayHasKey('why_not', $j['residual']['categories']['technology']);
        });
    }

    public function testRecordsAreScopedPerRole(): void
    {
        $paystack = (int) $this->entityByName('Paystack')['id'];
        self::assertSame(404, $this->call('GET', "/api/v1/risk-entities/{$paystack}", 'dev.vendor')->status, 'vendor cannot see other vendors');
        self::assertSame(200, $this->call('GET', "/api/v1/risk-entities/{$paystack}", 'dev.reviewer')->status);
        $jamb = (int) $this->entityByName('JAMB (Joint Admissions and Matriculation Board)')['id'];
        self::assertSame(200, $this->call('GET', "/api/v1/risk-entities/{$jamb}", 'dev.vendor')->status, 'vendor sees its own record');
    }

    public function testAdminAndAuditEndpointsAreRestricted(): void
    {
        foreach (['dev.business_owner', 'dev.reviewer', 'dev.vendor'] as $u) {
            self::assertSame(403, $this->call('GET', '/api/v1/admin/scoring-config', $u)->status, $u);
        }
        self::assertSame(200, $this->call('GET', '/api/v1/admin/scoring-config', 'dev.dpo')->status);
        self::assertSame(200, $this->call('GET', '/api/v1/audit-log', 'dev.dpo')->status);
        self::assertSame(403, $this->call('GET', '/api/v1/audit-log', 'dev.reviewer')->status);
    }

    public function testApprovalEndpointEnforcesRouting(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Microsoft 365')['id'];
            $body = ['decision' => 'approved', 'justification' => 'Fine by me.'];
            self::assertSame(403, $this->call('POST', "/api/v1/risk-entities/{$id}/approve", 'dev.business_owner', $body)->status);
            self::assertSame(403, $this->call('POST', "/api/v1/risk-entities/{$id}/approve", 'dev.reviewer', $body)->status);
            $ok = $this->call('POST', "/api/v1/risk-entities/{$id}/approve", 'dev.dpo', $body);
            self::assertSame(200, $ok->status);
            self::assertSame('approved', self::json($ok)['outcome']);
        });
    }

    public function testDashboardStoriesEndpoint(): void
    {
        $r = $this->call('GET', '/api/v1/dashboard/stories', 'dev.dpo');
        self::assertSame(200, $r->status);
        self::assertGreaterThanOrEqual(3, count(self::json($r)['data']));
    }

    public function testTrackerListsGapsNobodyOwns(): void
    {
        $body = $this->call('GET', '/remediation', 'dev.dpo')->body;
        self::assertStringContainsString('Gaps nobody owns yet', $body);
        $section = substr($body, strpos($body, 'id="unowned"'), strpos($body, 'Assigned actions') - strpos($body, 'id="unowned"'));
        self::assertStringContainsString('Campus CCTV with facial recognition', $section, 'in progress, nothing assigned');
        self::assertStringNotContainsString('>Paystack<', $section, 'approved: its remaining gaps were accepted');
        self::assertStringNotContainsString('Alumni newsletter', $section, 'a draft has no gaps yet');

        // a business owner gets the same tracker, limited to the records they own and worded for them
        $ada = $this->call('GET', '/remediation', 'dev.business_owner')->body;
        self::assertStringContainsString('Remediation on my records', $ada);
        self::assertStringContainsString('Campus CCTV with facial recognition', $ada);
        self::assertStringNotContainsString('Turnitin', $ada, "Tunde's record");
        self::assertStringNotContainsString('across the university', $ada);

        $risks = $this->call('GET', '/risks', 'dev.dpo')->body;
        self::assertStringContainsString('11 records · 10 scored · 1 not scored yet (draft)', $risks);
    }

    public function testEachProfileLandsOnItsOwnPage(): void
    {
        $dpo = $this->call('GET', '/dashboard', 'dev.dpo')->body;
        self::assertStringContainsString('<h1>Privacy risk dashboard</h1>', $dpo);
        self::assertStringContainsString('Waiting for your decision', $dpo);
        self::assertStringContainsString('Microsoft 365', $dpo, 'Medium risk: the DPO decides');

        $reviewer = $this->call('GET', '/dashboard', 'dev.reviewer')->body;
        self::assertStringContainsString('<h1>My review queue</h1>', $reviewer);
        self::assertStringContainsString('Processing Student Applications', $reviewer, 'Low risk: the Reviewer decides');
        self::assertStringNotContainsString('Microsoft 365', $reviewer);
        self::assertStringContainsString('Data Processing Agreement', $reviewer, 'evidence waiting to be checked');
        self::assertSame(200, $this->call('GET', '/overview', 'dev.reviewer')->status);

        $owner = $this->call('GET', '/dashboard', 'dev.business_owner')->body;
        self::assertStringContainsString('<h1>My work</h1>', $owner);
        self::assertStringContainsString('Submit for approval', $this->call('GET', '/dashboard', 'dev.business_owner2')->body);
        self::assertStringNotContainsString('Turnitin', $owner);
        self::assertSame(403, $this->call('GET', '/overview', 'dev.business_owner')->status);

        $vendor = $this->call('GET', '/dashboard', 'dev.vendor')->body;
        self::assertStringContainsString('What CU needs from you', $vendor);
        self::assertStringContainsString('JAMB', $vendor);
        self::assertStringNotContainsString('Paystack', $vendor);
        self::assertStringNotContainsString('badge-', $vendor, 'a vendor is not shown risk ratings');
    }

    public function testVendorCannotSeeCuPagesOrScoreItself(): void
    {
        $jamb = (int) $this->entityByName('JAMB (Joint Admissions and Matriculation Board)')['id'];
        foreach (['factors', 'inherent', 'residual', 'review', 'monitoring'] as $step) {
            $r = $this->call('GET', "/assessments/{$jamb}/{$step}", 'dev.vendor');
            self::assertSame(303, $r->status, $step);
            self::assertStringEndsWith("/assessments/{$jamb}/controls", $r->headers['Location']);
        }
        self::assertSame(200, $this->call('GET', "/assessments/{$jamb}/controls", 'dev.vendor')->status);
        self::assertSame(303, $this->call('GET', '/risks', 'dev.vendor')->status);
        self::assertSame(403, $this->call('PUT', "/api/v1/risk-entities/{$jamb}/factors", 'dev.vendor', [])->status);
        self::assertSame(403, $this->call('POST', "/api/v1/risk-entities/{$jamb}/score", 'dev.vendor')->status);
        self::assertSame(403, $this->call('POST', "/api/v1/risk-entities/{$jamb}/submit", 'dev.vendor')->status);
    }

    public function testMonitoringDoesNotLinkToRecordsTheUserCannotOpen(): void
    {
        $m365 = (int) $this->entityByName('Microsoft 365')['id'];
        $biometric = (int) $this->entityByName('Staff biometric attendance')['id']; // Tunde's, uses Bola's vendor
        $body = $this->call('GET', "/assessments/{$m365}/monitoring", 'dev.business_owner')->body;
        self::assertStringNotContainsString('/assessments/' . $biometric . '"', $body);
        self::assertStringContainsString('/assessments/' . $biometric . '"', $this->call('GET', "/assessments/{$m365}/monitoring", 'dev.dpo')->body);
    }

    public function testOutputIsEscapedInHtml(): void
    {
        $this->inRollback(function () {
            $req = $this->user('dev.business_owner');
            $id = $this->app()->assessments()->create($req, \App\Domain\EntityType::Vendor, [
                'name' => '<script>alert(1)</script>', 'department_id' => 1, 'service_description' => '"><img src=x onerror=alert(1)>',
            ]);
            $_SESSION = [DevAuthProvider::SESSION_KEY => 'dev.business_owner'];
            $r = (new Kernel($this->app()))->handle(new Request('GET', "/assessments/{$id}/subject", [], [], [], [], false));
            self::assertSame(200, $r->status);
            self::assertStringNotContainsString('<script>alert(1)</script>', $r->body);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $r->body);
            self::assertStringNotContainsString('<img src=x', $r->body);
        });
    }
}
