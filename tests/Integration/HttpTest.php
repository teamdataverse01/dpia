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
        $r = $this->call('POST', '/api/v1/risk-entities', 'dev.requestor', ['entity_type' => 'vendor'], false);
        self::assertSame(419, $r->status);
    }

    public function testCreateFollowsTheMatrix(): void
    {
        $this->inRollback(function () {
            $body = ['entity_type' => 'vendor', 'name' => 'Acme', 'department_id' => 1, 'service_description' => 'Things.'];
            foreach (['dev.auditor', 'dev.reviewer', 'dev.risk_approver', 'dev.infosec', 'dev.vendor'] as $u) {
                self::assertSame(403, $this->call('POST', '/api/v1/risk-entities', $u, $body)->status, $u);
            }
            $ok = $this->call('POST', '/api/v1/risk-entities', 'dev.requestor', $body);
            self::assertSame(201, $ok->status);
            self::assertSame('draft', self::json($ok)['status']);
        });
    }

    public function testValidationErrorFormat(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Fee payment processing')['id'];
            $f = self::studentApplicationFactors(false, (int) $this->entityByName('Paystack')['id']);
            $r = $this->call('PUT', "/api/v1/risk-entities/{$id}/factors", 'dev.requestor', $f);
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
            $r = $this->call('POST', "/api/v1/risk-entities/{$id}/score", 'dev.requestor');
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
        self::assertSame(200, $this->call('GET', "/api/v1/risk-entities/{$paystack}", 'dev.auditor')->status);
        $jamb = (int) $this->entityByName('JAMB (Joint Admissions and Matriculation Board)')['id'];
        self::assertSame(200, $this->call('GET', "/api/v1/risk-entities/{$jamb}", 'dev.vendor')->status, 'vendor sees its own record');
    }

    public function testAdminAndAuditEndpointsAreRestricted(): void
    {
        foreach (['dev.requestor', 'dev.reviewer', 'dev.privacy_dpo', 'dev.auditor'] as $u) {
            self::assertSame(403, $this->call('GET', '/api/v1/admin/scoring-config', $u)->status, $u);
        }
        self::assertSame(200, $this->call('GET', '/api/v1/admin/scoring-config', 'dev.admin')->status);
        self::assertSame(200, $this->call('GET', '/api/v1/audit-log', 'dev.auditor')->status);
        self::assertSame(403, $this->call('GET', '/api/v1/audit-log', 'dev.privacy_dpo')->status);
    }

    public function testApprovalEndpointEnforcesRouting(): void
    {
        $this->inRollback(function () {
            $id = (int) $this->entityByName('Microsoft 365')['id'];
            $body = ['decision' => 'approved', 'justification' => 'Fine by me.'];
            self::assertSame(403, $this->call('POST', "/api/v1/risk-entities/{$id}/approve", 'dev.requestor', $body)->status);
            self::assertSame(403, $this->call('POST', "/api/v1/risk-entities/{$id}/approve", 'dev.reviewer', $body)->status);
            $ok = $this->call('POST', "/api/v1/risk-entities/{$id}/approve", 'dev.privacy_dpo', $body);
            self::assertSame(200, $ok->status);
            self::assertSame('approved', self::json($ok)['outcome']);
        });
    }

    public function testDashboardStoriesEndpoint(): void
    {
        $r = $this->call('GET', '/api/v1/dashboard/stories', 'dev.privacy_dpo');
        self::assertSame(200, $r->status);
        self::assertGreaterThanOrEqual(3, count(self::json($r)['data']));
    }

    public function testOutputIsEscapedInHtml(): void
    {
        $this->inRollback(function () {
            $req = $this->user('dev.requestor');
            $id = $this->app()->assessments()->create($req, \App\Domain\EntityType::Vendor, [
                'name' => '<script>alert(1)</script>', 'department_id' => 1, 'service_description' => '"><img src=x onerror=alert(1)>',
            ]);
            $_SESSION = [DevAuthProvider::SESSION_KEY => 'dev.requestor'];
            $r = (new Kernel($this->app()))->handle(new Request('GET', "/assessments/{$id}/subject", [], [], [], [], false));
            self::assertSame(200, $r->status);
            self::assertStringNotContainsString('<script>alert(1)</script>', $r->body);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $r->body);
            self::assertStringNotContainsString('<img src=x', $r->body);
        });
    }
}
