<?php

declare(strict_types=1);

namespace Matrix;

use Closure;
use Matrix\Http\Request;
use Matrix\Http\Response;

class Router
{
    /** @var array<string, array<string, array{uri: string, handler: string|array|Closure}>> */
    protected array $routes = [];

    /**
     * 注册 GET 路由。
     */
    public function get(string $uri, string|array|Closure $handler): void
    {
        $this->addRoute('GET', $uri, $handler);
    }

    /**
     * 注册 POST 路由。
     */
    public function post(string $uri, string|array|Closure $handler): void
    {
        $this->addRoute('POST', $uri, $handler);
    }

    /**
     * 分发请求到匹配的路由处理器。
     */
    public function dispatch(Request $request, Container $container): Response
    {
        $method = $request->getMethod();
        $path   = $request->getPath();

        foreach ($this->routes[$method] ?? [] as $route) {
            $params = $this->matchRoute($route['uri'], $path);
            if ($params !== null) {
                $request->setRouteParams($params);
                return $this->callHandler($route['handler'], $request, $container);
            }
        }

        // 404
        $response = new Response();
        $response->setStatusCode(404);
        $response->setContent('404 Not Found');
        return $response;
    }

    protected function addRoute(string $method, string $uri, string|array|Closure $handler): void
    {
        $this->routes[$method][] = [
            'uri'     => rtrim($uri, '/') ?: '/',
            'handler' => $handler,
        ];
    }

    /**
     * 匹配 URI 并提取路径参数。
     *
     * @return array<string, string>|null 匹配成功返回参数数组，失败返回 null
     */
    protected function matchRoute(string $routeUri, string $requestPath): ?array
    {
        $requestPath = rtrim($requestPath, '/') ?: '/';

        // 简单静态匹配
        if ($routeUri === $requestPath) {
            return [];
        }

        // 动态参数匹配  /api/user/{id}
        // 分割出静态段和 {param}，对静态段 preg_quote 避免正则字符误匹配
        $parts = preg_split('/(\{\w+\})/', $routeUri, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pattern = '#^';
        foreach ($parts as $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $m)) {
                $pattern .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $pattern .= preg_quote($part, '#');
            }
        }
        $pattern .= '$#';

        if (preg_match($pattern, $requestPath, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return null;
    }

    /**
     * 调用路由处理器：闭包或 [Controller::class, 'method']。
     */
    protected function callHandler(string|array|Closure $handler, Request $request, Container $container): Response
    {
        if ($handler instanceof Closure) {
            return $container->callClosure($handler, $request);
        }

        // $handler = [ControllerClass::class, 'method']
        [$class, $method] = $handler;
        $controller = $container->make($class);
        return $container->callMethod($controller, $method, $request);
    }
}
