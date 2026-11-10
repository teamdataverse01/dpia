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
            ['username' => 'dpo', 'name' => 'Dami Ogun', 'role' => 'DPO / Administrator', 'where' => 'CU staff',
                'does' => 'Runs privacy across the university. Sees everything, including the audit log. Approves or rejects Medium and High risks, is the only one who can accept a risk for a limited time, and sets up scoring, controls, templates and who has which role.',
                'try' => 'The dashboard, starting with “Waiting for your decision”.'],
            ['username' => 'reviewer', 'name' => 'Efe Ibrahim', 'role' => 'Reviewer', 'where' => 'CU staff',
                'does' => 'The independent checker. Works from a queue: verifies that uploaded evidence is genuine, approves Low-risk assessments, and confirms when a Low risk is small enough to be Very Low. Does not browse the risk register or remediation tracker.',
                'try' => 'My queue: evidence to check and assessments to approve.'],
            ['username' => 'owner', 'name' => 'Bola Adeyemi', 'role' => 'CU Activity Owner', 'where' => 'CU staff',
                'does' => 'The CU staff member responsible for a data activity or a vendor relationship, such as the Head of Admissions. Starts the DPIA or vendor assessment, answers the risk questions, records safeguards with evidence, assigns fixes and submits for approval. Sees only their own department’s risks and remediation.',
                'try' => 'My work, then Risks and Remediation (Admissions only).'],
            ['username' => 'vendor', 'name' => 'JAMB contact', 'role' => 'Vendor', 'where' => 'Outside company',
                'does' => 'A contact at an outside company that handles CU data. Sees only what CU needs from their company and uploads the proof. Cannot see CU’s scores, approvals or anything else.',
                'try' => 'What CU needs: the list of documents to upload.'],
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
