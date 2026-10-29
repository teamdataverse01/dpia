<?php

declare(strict_types=1);

namespace App\Http;

use App\App;
use App\Controllers\Api;
use App\Controllers\Web;
use App\Domain\PortalUser;
use App\Infrastructure\DemoAuthProvider;
use App\Infrastructure\DevAuthProvider;
use App\Support\ConflictException;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/** Front-controller pipeline: session → routing → authentication → CSRF → controller → error mapping. */
final class Kernel
{
    private Router $router;

    public function __construct(private readonly App $app)
    {
        View::$basePath = rtrim((string) $app->config->get('APP_BASE_PATH', ''), '/');
        $this->router = new Router();
        $this->routes();
    }

    private function routes(): void
    {
        $r = $this->router;
        $a = $this->app;
        $dash = new Web\DashboardController($a);
        $wiz = new Web\WizardController($a);
        $tpl = new Web\TemplateController($a);
        $adm = new Web\AdminController($a);
        $dev = new Web\DevController($a);

        // dev-only identity switcher (no passwords; refuses to run outside APP_ENV=dev)
        $r->get('/dev/switch-user', [$dev, 'form']);
        $r->post('/dev/switch-user', [$dev, 'switch']);
        // demo-deployment sign-in (AUTH_ADAPTER=demo only)
        $login = new Web\DemoLoginController($a);
        $r->get('/login', [$login, 'form']);
        $r->post('/login', [$login, 'login']);
        $r->post('/logout', [$login, 'logout']);

        $r->get('/', fn () => Response::redirect(url('/dashboard')));
        $r->get('/dashboard', [$dash, 'dashboard']);
        $r->get('/risks', [$dash, 'risks']);
        $r->get('/remediation', [$dash, 'remediation']);
        $r->post('/remediation/{id}', [$dash, 'updateAction']);
        $r->post('/remediation/{id}/remind', [$dash, 'remindOwner']);
        $r->get('/help', [$dash, 'help']);

        // intake (stage 1)
        $r->get('/vendors/new', [$wiz, 'newVendor']);
        $r->post('/vendors', [$wiz, 'createVendor']);
        $r->get('/dpias/new', [$tpl, 'fastPath']);
        $r->get('/dpias/new/intake', [$wiz, 'newDpia']);
        $r->post('/dpias', [$wiz, 'createDpia']);

        // fast path
        $r->get('/templates', [$tpl, 'index']);
        $r->post('/templates/rescore', [$tpl, 'rescore']);
        $r->get('/templates/{id}', [$tpl, 'preview']);
        $r->post('/templates/{id}/start', [$tpl, 'start']);

        // the ten shared screens
        $r->get('/assessments/{id}', [$wiz, 'show']);
        $r->get('/assessments/{id}/subject', [$wiz, 'subject']);
        $r->post('/assessments/{id}/subject', [$wiz, 'saveSubject']);
        $r->get('/assessments/{id}/factors', [$wiz, 'factors']);
        $r->post('/assessments/{id}/factors', [$wiz, 'saveFactors']);
        $r->get('/assessments/{id}/transfers', [$wiz, 'transfers']);
        $r->post('/assessments/{id}/transfers', [$wiz, 'saveTransfers']);
        $r->get('/assessments/{id}/inherent', [$wiz, 'inherent']);
        $r->post('/assessments/{id}/rescore', [$wiz, 'rescore']);
        $r->get('/assessments/{id}/recommended', [$wiz, 'recommended']);
        $r->post('/assessments/{id}/recommended', [$wiz, 'startControls']);
        $r->get('/assessments/{id}/controls', [$wiz, 'controls']);
        $r->post('/assessments/{id}/controls/add', [$wiz, 'addControl']);
        $r->post('/assessments/{id}/controls/{cid}', [$wiz, 'recordControl']);
        $r->post('/assessments/{id}/controls/{cid}/verify', [$wiz, 'verify']);
        $r->get('/assessments/{id}/controls/{cid}/evidence', [$wiz, 'evidence']);
        $r->get('/assessments/{id}/residual', [$wiz, 'residual']);
        $r->post('/assessments/{id}/residual', [$wiz, 'calculateResidual']);
        $r->post('/assessments/{id}/negligible', [$wiz, 'negligible']);
        $r->get('/assessments/{id}/remediation', [$wiz, 'remediation']);
        $r->post('/assessments/{id}/remediation', [$wiz, 'createAction']);
        $r->get('/assessments/{id}/review', [$wiz, 'review']);
        $r->post('/assessments/{id}/submit', [$wiz, 'submit']);
        $r->post('/assessments/{id}/decide', [$wiz, 'decide']);
        $r->get('/assessments/{id}/monitoring', [$wiz, 'monitoring']);
        $r->post('/assessments/{id}/reassess', [$wiz, 'reassess']);
        $r->post('/assessments/{id}/save-template', [$tpl, 'saveFromEntity']);

        // administration and audit
        $r->get('/admin', fn () => Response::redirect(url('/admin/config')));
        $r->get('/admin/config', [$adm, 'config']);
        $r->post('/admin/config', [$adm, 'saveConfig']);
        $r->get('/admin/factor-options', [$adm, 'factorOptions']);
        $r->post('/admin/factor-options', [$adm, 'createFactorOption']);
        $r->post('/admin/factor-options/{id}', [$adm, 'saveFactorOption']);
        $r->get('/admin/controls', [$adm, 'controls']);
        $r->get('/admin/controls/new', [$adm, 'editControl']);
        $r->get('/admin/controls/{id}', [$adm, 'editControl']);
        $r->post('/admin/controls', [$adm, 'saveControl']);
        $r->post('/admin/controls/{id}', [$adm, 'saveControl']);
        $r->get('/admin/users', [$adm, 'users']);
        $r->post('/admin/users', [$adm, 'addUserRole']);
        $r->post('/admin/users/{id}/delete', [$adm, 'removeUserRole']);
        $r->get('/audit', [$adm, 'audit']);

        // REST API (PRD 14)
        Api\Routes::register($r, $a);
    }

    public function handle(Request $request): Response
    {
        try {
            $match = $this->router->match($request->method, $request->path);
            if ($match === null) {
                throw new NotFoundException('Page not found.');
            }
            if ($match === false) {
                return $request->isApi()
                    ? Response::jsonError('method_not_allowed', 'Method not allowed.', 405)
                    : Response::html('Method not allowed', 405);
            }
            [$handler, $params] = $match;
            $request->params = $params;

            $isPublicRoute = str_starts_with($request->path, '/dev/') || in_array($request->path, ['/login', '/logout'], true);
            $user = null;
            if (!$isPublicRoute) {
                $user = $this->authenticate($request);
                if ($user instanceof Response) {
                    return $user;
                }
            }
            if (!Csrf::valid($request)) {
                return $request->isApi()
                    ? Response::jsonError('csrf_failed', 'Missing or invalid CSRF token. Send the X-CSRF-Token header.', 419)
                    : $this->errorPage(419, 'Your form expired', 'Please go back, refresh the page and try again.');
            }
            return $handler($request, $user);
        } catch (ValidationException $e) {
            return $request->isApi()
                ? Response::jsonError('validation_failed', $e->getMessage(), 422, $e->fields)
                : $this->flashBack($request, $e->getMessage() . ($e->fields ? ' ' . implode(' ', $e->fields) : ''));
        } catch (NotFoundException $e) {
            return $request->isApi() ? Response::jsonError('not_found', $e->getMessage(), 404) : $this->errorPage(404, 'Not found', $e->getMessage());
        } catch (ForbiddenException $e) {
            return $request->isApi() ? Response::jsonError('forbidden', $e->getMessage(), 403) : $this->errorPage(403, 'Not allowed', $e->getMessage());
        } catch (ConflictException $e) {
            return $request->isApi() ? Response::jsonError('conflict', $e->getMessage(), 409) : $this->flashBack($request, $e->getMessage());
        } catch (\Throwable $e) {
            error_log((string) $e);
            $detail = $this->app->config->isDev() ? get_class($e) . ': ' . $e->getMessage() : 'Something went wrong. The error has been logged.';
            return $request->isApi() ? Response::jsonError('server_error', $detail, 500) : $this->errorPage(500, 'Something went wrong', $detail);
        }
    }

    /** Identity from the portal (AuthProvider) + module roles from user_roles. */
    private function authenticate(Request $request): PortalUser|Response
    {
        $portalUser = $this->app->auth()->currentUser();
        if ($portalUser === null) {
            if ($request->isApi()) {
                return Response::jsonError('unauthenticated', 'No portal session.', 401);
            }
            $loginUrl = $this->app->auth()->loginUrl($_SERVER['REQUEST_URI'] ?? '/');
            // the dev switcher and demo sign-in live inside this module; the real portal login is an absolute URL
            $auth = $this->app->auth();
            return Response::redirect($auth instanceof DevAuthProvider || $auth instanceof DemoAuthProvider ? url($loginUrl) : $loginUrl);
        }
        $roles = $this->app->refs()->rolesFor($portalUser->id);
        $user = $portalUser->withRoles($roles['roles'], $roles['departments']);
        $view = $this->app->view();
        $view->share('currentUser', $user);
        $view->share('authz', $this->app->authz());
        $view->share('isDevAuth', $this->app->auth() instanceof DevAuthProvider);
        $view->share('isDemoAuth', $this->app->auth() instanceof DemoAuthProvider);
        $view->share('flash', self::takeFlash());
        return $user;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    private static function takeFlash(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    private function flashBack(Request $request, string $message): Response
    {
        self::flash('error', $message);
        $back = $request->header('referer');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($back !== null && $host !== '' && parse_url($back, PHP_URL_HOST) === explode(':', $host)[0]) {
            return Response::redirect($back);
        }
        return Response::redirect(url('/dashboard'));
    }

    private function errorPage(int $status, string $title, string $message): Response
    {
        $view = $this->app->view();
        $view->share('flash', []);
        return $view->render('error', ['title' => $title, 'message' => $message, 'status' => $status], $status);
    }
}
