<?php

declare(strict_types=1);

namespace App\Http;

/** Renders PHP templates from /templates inside the shared layout. All output is escaped with e(). */
final class View
{
    public static string $basePath = '';

    /** @var array<string,mixed> shared variables (current user, flash, nav) */
    private array $shared = [];

    public function __construct(private readonly string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $template, array $vars = [], int $status = 200, ?string $layout = 'layout'): Response
    {
        $content = $this->partial($template, $vars);
        if ($layout !== null) {
            $content = $this->partial($layout, $vars + ['content' => $content]);
        }
        return Response::html($content, $status);
    }

    public function partial(string $template, array $vars = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        $reserved = array_intersect_key($this->shared + $vars, array_flip(['template', 'vars', 'file', 'view', 'this']));
        if ($reserved !== []) {
            throw new \LogicException('Reserved view variable name(s): ' . implode(', ', array_keys($reserved)));
        }
        $view = $this;
        extract($this->shared + $vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
