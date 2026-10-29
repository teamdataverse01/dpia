<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DemoAuthProvider;
use App\Support\NotFoundException;

/** Sign-in / sign-out for the demo deployment only (AUTH_ADAPTER=demo). */
final class DemoLoginController extends BaseController
{
    private function provider(): DemoAuthProvider
    {
        $auth = $this->app->auth();
        if (!$auth instanceof DemoAuthProvider) {
            throw new NotFoundException('Page not found.');
        }
        return $auth;
    }

    public function form(Request $request, ?string $error = null, string $username = ''): Response
    {
        $this->provider();
        // The accounts shown on the sign-in page, in demo order (the other demo accounts still sign in).
        $accounts = [
            ['username' => 'dpo', 'name' => 'Dami Ogun', 'role' => 'Privacy Team / DPO',
                'does' => 'Runs privacy across the university. Sees every vendor and DPIA, approves or rejects assessments (Medium and High risk), and is the only one who can accept a risk for a limited time. Saves approved DPIAs as templates.',
                'try' => 'The university-wide dashboard; approving Microsoft 365.'],
            ['username' => 'requestor', 'name' => 'Ada Okafor', 'role' => 'Requestor / Activity Owner',
                'does' => 'Starts a vendor assessment or DPIA for an activity they are responsible for, answers the risk questions, assigns the fixes and submits it for approval. Sees only their own records.',
                'try' => 'Creating a DPIA from a template; her “My risks” page (6 records).'],
            ['username' => 'requestor2', 'name' => 'Tunde Bakare', 'role' => 'Requestor / Activity Owner',
                'does' => 'Same role as Ada, for different activities.',
                'try' => 'That he sees only his own 5 records, not Ada’s.'],
            ['username' => 'owner', 'name' => 'Bola Adeyemi', 'role' => 'Business Owner',
                'does' => 'Runs the process day to day. Records which safeguards (controls) are in place, how effective they are, and uploads the evidence.',
                'try' => 'Recording a control as implemented with evidence (step 6).'],
            ['username' => 'reviewer', 'name' => 'Efe Ibrahim', 'role' => 'Reviewer',
                'does' => 'The independent checker. Verifies that uploaded evidence is genuine, approves Low-risk assessments, and confirms when a Low risk is small enough to be Very Low.',
                'try' => 'Verifying a piece of evidence (step 6).'],
            ['username' => 'vendor', 'name' => 'JAMB contact', 'role' => 'Vendor',
                'does' => 'A contact at an outside company. Sees only their own company’s vendor record and can supply its evidence.',
                'try' => 'That only JAMB is visible.'],
            ['username' => 'auditor', 'name' => 'Halima Musa', 'role' => 'Auditor',
                'does' => 'Independent oversight. Can see everything, including the full audit log of who changed what, but cannot change anything.',
                'try' => 'The audit log.'],
            ['username' => 'admin', 'name' => 'Gbenga Lawal', 'role' => 'Administrator',
                'does' => 'Sets up the system: scoring weights and thresholds, the control library, and who has which role. Can do everything.',
                'try' => 'Admin → Scoring configuration (look, don’t save).'],
        ];
        $this->app->view()->share('flash', []);
        return $this->render('auth/login', [
            'title' => 'Sign in',
            'accounts' => $accounts,
            'error' => $error,
            'username' => $username,
            'returnTo' => (string) ($request->query('return') ?? $request->input('return', '')),
        ], $error ? 401 : 200);
    }

    public function login(Request $request): Response
    {
        $username = (string) $request->input('username', '');
        if (!$this->provider()->attempt($username, (string) $request->input('password', ''))) {
            return $this->form($request, 'That username or password is not right.', $username);
        }
        $return = (string) $request->input('return', '');
        if ($return === '' || !str_starts_with($return, '/') || str_starts_with($return, '//')) {
            return $this->redirect('/dashboard');
        }
        return Response::redirect($return);
    }

    public function logout(Request $request): Response
    {
        $this->provider()->logout();
        return $this->redirect('/login');
    }
}
