<?php

declare(strict_types=1);

namespace Matrix;

use Closure;
use DI\Container;
use DI\ContainerBuilder;
use FastRoute\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Matrix\Routing\RouteCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

class Application
{
    protected Container $container;

    /** @var array<callable> */
    protected array $routes = [];

    /**
     * @param array<string, mixed>|null $dbConfig Eloquent 数据库配置
     */
    public function __construct(?array $dbConfig = null)
    {
        // 注册 Whoops 优雅错误页
        $whoops = new Run();
        $whoops->pushHandler(new PrettyPageHandler());
        $whoops->register();

        // 初始化 PHP-DI 容器
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $this->container = $builder->build();

        // 启动 Eloquent ORM
        if ($dbConfig !== null) {
            $this->bootEloquent($dbConfig);
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * 加载路由文件，文件中 $router 变量可用。
     */
    public function loadRoutes(string $file): self
    {
        $router = new RouteCollector();
        require $file;
        $this->routes = $router->getRoutes();
        return $this;
    }

    /**
     * 处理 HTTP 请求并返回响应。
     */
    public function handle(Request $request): Response
    {
        $dispatcher = \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
            foreach ($this->routes as $route) {
                // 将路由处理器包裹在中间件洋葱中
                $handler = $this->wrapMiddleware($route['handler'], $route['middleware']);
                $r->addRoute($route['method'], $route['uri'], $handler);
            }
        });

        $routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getPathInfo());

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return new Response('404 Not Found', 404);

            case Dispatcher::METHOD_NOT_ALLOWED:
                return new Response('405 Method Not Allowed', 405);

            case Dispatcher::FOUND:
                [, $handler, $vars] = $routeInfo;

                foreach ($vars as $key => $value) {
                    $request->attributes->set($key, $value);
                }

                $this->container->set(Request::class, $request);

                // $handler 已被 wrapMiddleware 包裹为 Closure
                $result = $handler();

                if ($result instanceof Response) {
                    return $result;
                }
                return new Response((string) $result);
        }

        return new Response('Internal Server Error', 500);
    }

    /**
     * 将中间件按洋葱模型包裹核心处理器。
     *
     * @param array<callable> $middleware
     */
    protected function wrapMiddleware(string|array|Closure $handler, array $middleware): Closure
    {
        $core = function () use ($handler) {
            if (is_array($handler) && count($handler) === 2) {
                [$class, $method] = $handler;
                $controller = $this->container->get($class);
                return $this->container->call([$controller, $method]);
            }
            return $this->container->call($handler);
        };

        // 从最内层向外构建洋葱
        foreach (array_reverse($middleware) as $mw) {
            $core = function () use ($mw, $core) {
                return $mw($this->container->get(Request::class), $core);
            };
        }

        return $core;
    }

    /**
     * 启动 Laravel Eloquent ORM Capsule。
     *
     * @param array<string, mixed> $config
     */
    protected function bootEloquent(array $config): void
    {
        $capsule = new Capsule();
        $capsule->addConnection($config);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}
