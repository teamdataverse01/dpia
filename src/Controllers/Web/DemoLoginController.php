<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DemoAuthProvider;
use App\Infrastructure\DevAuthProvider;
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
        $accounts = [];
        foreach (DemoAuthProvider::usernames() as $username2 => $id) {
            $accounts[] = [
                'username' => $username2,
                'name' => DevAuthProvider::USERS[$id][0],
                'roles' => $this->app->refs()->rolesFor($id)['roles'],
            ];
        }
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
