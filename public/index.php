<?php

declare(strict_types=1);

use App\App;
use App\Http\Kernel;
use App\Http\Request;
use App\Support\Config;

$root = dirname(__DIR__);

// Built-in dev server: let it serve static assets directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file) && str_starts_with(realpath($file) ?: '', realpath(__DIR__ . '/assets') ?: "\0")) {
        return false;
    }
}

require $root . '/vendor/autoload.php';

$config = Config::load($root . '/.env');

// Inside the CU portal the session is normally shared with the portal (PortalSessionAuthProvider).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => str_starts_with((string) $config->get('APP_BASE_URL', ''), 'https://'),
        'path' => '/',
    ]);
    session_start();
}

$app = new App($config, $root);
(new Kernel($app))->handle(Request::fromGlobals(rtrim((string) $config->get('APP_BASE_PATH', ''), '/')))->send();
