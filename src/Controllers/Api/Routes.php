<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\App;
use App\Http\Router;

/** REST API routes (PRD 14). Base path /api/v1. All JSON; all behind AuthProvider + Authorizer + CSRF. */
final class Routes
{
    public static function register(Router $r, App $app): void
    {
        $e = new RiskEntityApi($app);
        $l = new LibraryApi($app);
        $d = new DashboardApi($app);
        $p = '/api/v1';

        $r->get("{$p}/session", [$l, 'session']);

        $r->post("{$p}/risk-entities", [$e, 'create']);
        $r->get("{$p}/risk-entities", [$e, 'list']);
        $r->get("{$p}/risk-entities/{id}", [$e, 'show']);
        $r->put("{$p}/risk-entities/{id}/factors", [$e, 'factors']);
        $r->post("{$p}/risk-entities/{id}/score", [$e, 'score']);
        $r->get("{$p}/risk-entities/{id}/recommended-controls", [$e, 'recommended']);
        $r->post("{$p}/risk-entities/{id}/start-controls", [$e, 'startControls']);
        $r->put("{$p}/risk-entities/{id}/controls/{controlId}", [$e, 'recordControl']);
        $r->post("{$p}/risk-entities/{id}/controls/{controlId}/verify", [$e, 'verify']);
        $r->post("{$p}/risk-entities/{id}/evidence", [$e, 'evidence']);
        $r->post("{$p}/risk-entities/{id}/confirm-negligible", [$e, 'confirmNegligible']);
        $r->get("{$p}/risk-entities/{id}/residual", [$e, 'residual']);
        $r->post("{$p}/risk-entities/{id}/residual", [$e, 'calculateResidual']);
        $r->post("{$p}/risk-entities/{id}/submit", [$e, 'submit']);
        $r->post("{$p}/risk-entities/{id}/approve", [$e, 'approve']);
        $r->post("{$p}/risk-entities/{id}/reassess", [$e, 'reassess']);
        $r->post("{$p}/risk-entities/{id}/apply-template", [$l, 'applyTemplate']);
        $r->post("{$p}/score-preview", [$e, 'preview']);

        $r->post("{$p}/remediation-actions", [$e, 'createAction']);
        $r->get("{$p}/remediation-actions", [$e, 'listActions']);
        $r->patch("{$p}/remediation-actions/{id}", [$e, 'updateAction']);

        $r->get("{$p}/controls", [$l, 'controls']);
        $r->post("{$p}/controls", [$l, 'createControl']);
        $r->put("{$p}/controls/{id}", [$l, 'updateControl']);

        $r->get("{$p}/activity-templates", [$l, 'templates']);
        $r->get("{$p}/activity-templates/match", [$l, 'matchTemplates']);
        $r->post("{$p}/activity-templates", [$l, 'saveTemplate']);

        $r->get("{$p}/dashboard/overview", [$d, 'overview']);
        $r->get("{$p}/dashboard/by-department", [$d, 'byDepartment']);
        $r->get("{$p}/dashboard/remediation", [$d, 'remediation']);
        $r->get("{$p}/dashboard/stories", [$d, 'stories']);

        $r->get("{$p}/admin/scoring-config", [$l, 'getConfig']);
        $r->put("{$p}/admin/scoring-config", [$l, 'putConfig']);
        $r->get("{$p}/admin/factor-options", [$l, 'getFactorOptions']);
        $r->put("{$p}/admin/factor-options", [$l, 'putFactorOptions']);

        $r->get("{$p}/audit-log", [$l, 'auditLog']);
    }
}
