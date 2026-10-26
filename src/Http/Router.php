<?php

declare(strict_types=1);

namespace App\Http;

/** Minimal method + pattern router. Patterns use {name} (digits) or {name:regex}. */
final class Router
{
    /** @var list<array{method:string,regex:string,names:list<string>,handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function ($m) use (&$names) {
            $names[] = $m[1];
            return '(' . ($m[2] ?? '\d+') . ')';
        }, $pattern);
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'names' => $names, 'handler' => $handler];
    }

    /**
     * @return array{0: callable, 1: array<string,string>}|null|false null = no path match, false = path matched but not method
     */
    public function match(string $method, string $path): array|null|false
    {
        $pathMatched = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($r['method'] !== $method && !($method === 'HEAD' && $r['method'] === 'GET')) {
                continue;
            }
            $params = [];
            foreach ($r['names'] as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            return [$r['handler'], $params];
        }
        return $pathMatched ? false : null;
    }
}
