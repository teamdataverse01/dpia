<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\App;
use App\Domain\PortalUser;
use App\Http\Request;
use App\Http\Response;
use App\Services\DashboardService;

/** Dashboard data (PRD 11, 14). Supports basis=residual|inherent, entity_type, department_id, from, to. */
final class DashboardApi
{
    public function __construct(private readonly App $app)
    {
    }

    public function overview(Request $request, PortalUser $user): Response
    {
        return Response::json($this->app->dashboard()->overview($user, DashboardService::filters($request->query)));
    }

    public function byDepartment(Request $request, PortalUser $user): Response
    {
        return Response::json($this->app->dashboard()->byDepartment($user, DashboardService::filters($request->query)));
    }

    public function remediation(Request $request, PortalUser $user): Response
    {
        return Response::json($this->app->dashboard()->remediation($user, DashboardService::filters($request->query)));
    }

    public function stories(Request $request, PortalUser $user): Response
    {
        $stories = $this->app->dashboard()->stories($user, DashboardService::filters($request->query));
        foreach ($stories as &$s) {
            $s['link'] = url($s['link']);
        }
        unset($s);
        return Response::json(['data' => $stories]);
    }
}
