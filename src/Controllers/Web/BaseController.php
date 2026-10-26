<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\App;
use App\Http\Kernel;
use App\Http\Response;

abstract class BaseController
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function render(string $template, array $vars = [], int $status = 200): Response
    {
        return $this->app->view()->render($template, $vars, $status);
    }

    protected function redirect(string $path, array $query = []): Response
    {
        return Response::redirect(url($path, $query));
    }

    protected function flash(string $type, string $message): void
    {
        Kernel::flash($type, $message);
    }
}
