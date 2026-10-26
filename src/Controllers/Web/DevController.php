<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DevAuthProvider;
use App\Support\NotFoundException;

/** DEVELOPMENT ONLY: choose which seeded portal user to act as. Not a login — there are no passwords. */
final class DevController extends BaseController
{
    private function provider(): DevAuthProvider
    {
        $auth = $this->app->auth();
        if (!$auth instanceof DevAuthProvider || !$this->app->config->isDev()) {
            throw new NotFoundException('Page not found.');
        }
        return $auth;
    }

    public function form(Request $request): Response
    {
        $this->provider();
        $users = [];
        foreach (DevAuthProvider::USERS as $id => [$name, $email]) {
            $users[] = ['id' => $id, 'name' => $name, 'roles' => $this->app->refs()->rolesFor($id)['roles']];
        }
        $this->app->view()->share('flash', []);
        return $this->render('dev/switch_user', [
            'users' => $users,
            'returnTo' => (string) $request->query('return', ''),
            'current' => $_SESSION[DevAuthProvider::SESSION_KEY] ?? null,
        ]);
    }

    public function switch(Request $request): Response
    {
        $this->provider()->switchTo((string) $request->input('user'));
        $return = (string) $request->input('return', '');
        // only allow local paths
        if ($return === '' || !str_starts_with($return, '/') || str_starts_with($return, '//')) {
            return $this->redirect('/dashboard');
        }
        return Response::redirect($return);
    }
}
