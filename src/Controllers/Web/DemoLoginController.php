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
            ['username' => 'dpo', 'who' => 'Dami Ogun, Privacy Team / DPO', 'shows' => 'the dashboard and approvals'],
            ['username' => 'requestor', 'who' => 'Ada Okafor, Activity Owner (owns 6 records)', 'shows' => 'creating a DPIA'],
            ['username' => 'requestor2', 'who' => 'Tunde Bakare, Activity Owner (owns the other 5)', 'shows' => 'that requestors only see their own records'],
            ['username' => 'approver', 'who' => 'Funmi Bello, Risk Approver', 'shows' => 'High-risk sign-off'],
            ['username' => 'vendor', 'who' => "JAMB's contact", 'shows' => 'that a vendor sees only its own record'],
            ['username' => 'auditor', 'who' => 'Halima Musa, Auditor', 'shows' => 'the read-only audit log'],
            ['username' => 'admin', 'who' => 'Gbenga Lawal, Administrator', 'shows' => 'scoring settings'],
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
