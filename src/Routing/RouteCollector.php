<?php

declare(strict_types=1);

namespace Matrix\Routing;

use Closure;

class RouteCollector
{
    /** @var array<int, array{method: string, uri: string, handler: string|array|Closure, middleware: array<callable>}> */
    protected array $routes = [];

    /** @var array<callable> */
    protected array $middlewareStack = [];

    /**
     * 为下一条路由注册中间件（可多次调用堆叠）。
     */
    public function middleware(callable $mw): self
    {
        $this->middlewareStack[] = $mw;
        return $this;
    }

    public function get(string $uri, string|array|Closure $handler): void
    {
        $this->addRoute('GET', $uri, $handler);
    }

    public function post(string $uri, string|array|Closure $handler): void
    {
        $this->addRoute('POST', $uri, $handler);
    }

    public function put(string $uri, string|array|Closure $handler): void
    {
        $this->addRoute('PUT', $uri, $handler);
    }

    public function delete(string $uri, string|array|Closure $handler): void
    {
        $this->addRoute('DELETE', $uri, $handler);
    }

    protected function addRoute(string $method, string $uri, string|array|Closure $handler): void
    {
        $this->routes[] = [
            'method'     => $method,
            'uri'        => $uri,
            'handler'    => $handler,
            'middleware' => $this->middlewareStack,
        ];

        // 清空中间件栈，下一路由重新计算
        $this->middlewareStack = [];
    }

    /**
     * @return array<int, array{method: string, uri: string, handler: string|array|Closure, middleware: array<callable>}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
