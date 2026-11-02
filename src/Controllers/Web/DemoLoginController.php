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
        $accounts = [
            ['username' => 'dpo', 'name' => 'Dami Ogun', 'role' => 'DPO / Administrator',
                'does' => 'Runs privacy across the university. Sees everything, including the audit log. Approves or rejects Medium and High risks, is the only one who can accept a risk for a limited time, and sets up scoring, controls, templates and who has which role.',
                'try' => 'The university-wide dashboard; approving Microsoft 365; Admin → Scoring configuration (look, don’t save).'],
            ['username' => 'reviewer', 'name' => 'Efe Ibrahim', 'role' => 'Reviewer',
                'does' => 'The independent checker. Sees everything, verifies that uploaded evidence is genuine, approves Low-risk assessments, and confirms when a Low risk is small enough to be Very Low.',
                'try' => 'Verifying a piece of evidence (step 6).'],
            ['username' => 'owner', 'name' => 'Bola Adeyemi', 'role' => 'Business Owner',
                'does' => 'Runs the activity or the vendor relationship at CU. Starts the vendor assessment or DPIA, answers the risk questions, records which safeguards are in place with evidence, assigns the fixes and submits for approval. Sees only their own records.',
                'try' => 'Creating a DPIA from a template; the “My risks” page (6 records, not all 10).'],
            ['username' => 'vendor', 'name' => 'JAMB contact', 'role' => 'Vendor',
                'does' => 'A contact at an outside company. Sees only their own company’s vendor record and can supply its evidence.',
                'try' => 'That only JAMB is visible.'],
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
