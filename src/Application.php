<?php

declare(strict_types=1);

namespace Matrix;

use Closure;
use DebugBar\DataCollector\PDO\PDOCollector;
use DebugBar\DataCollector\PDO\TraceablePDO;
use DebugBar\StandardDebugBar;
use DI\Container;
use DI\ContainerBuilder;
use FastRoute\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Matrix\Middleware\DebugBarMiddleware;
use Matrix\Routing\RouteCollector;
use PDO;
use Spatie\Ignition\Ignition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Application
{
    protected Container $container;

    /** @var array<callable> */
    protected array $routes = [];

    /** @var array<callable> */
    protected array $globalMiddleware = [];

    protected ?StandardDebugBar $debugBar = null;

    protected ?Capsule $capsule = null;

    /**
     * @param array<string, mixed> $options {
     *     database?: array,   // Eloquent 数据库配置
     *     debug?: bool,       // 是否启用 Ignition 错误页
     * }
     */
    public function __construct(array $options = [])
    {
        // Spatie Ignition 错误页
        if ($options['debug'] ?? ($_ENV['APP_DEBUG'] ?? 'true') === 'true') {
            Ignition::make()->register();
        }

        // 初始化 PHP-DI 容器
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $this->container = $builder->build();

        // 启动 Eloquent ORM
        if (isset($options['database'])) {
            $this->bootEloquent($options['database']);
        }

        // 初始化 DebugBar
        $this->debugBar = new StandardDebugBar();
        $this->container->set(StandardDebugBar::class, $this->debugBar);

        // 如果 Eloquent 已启动，挂载 PDO 追踪
        if ($this->capsule !== null) {
            $this->bootDebugBarPdo();
        }

        // 注册全局 DebugBar 中间件
        $this->addGlobalMiddleware([new DebugBarMiddleware($this->debugBar)]);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getDebugBar(): ?StandardDebugBar
    {
        return $this->debugBar;
    }

    /**
     * 注册全局中间件（所有请求生效）。
     */
    public function addGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = array_merge($this->globalMiddleware, $middleware);
    }

    /**
     * 加载路由文件，文件中 $router 变量可用。
     */
    public function loadRoutes(string $file): self
    {
        $router = new RouteCollector();
        $app = $this;
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
                $handler = $this->wrapMiddleware($route['handler'], $route['middleware']);
                $r->addRoute($route['method'], $route['uri'], $handler);
            }
        });

        $routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getPathInfo());

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                $core = fn() => new Response('404 Not Found', 404);
                break;

            case Dispatcher::METHOD_NOT_ALLOWED:
                $core = fn() => new Response('405 Method Not Allowed', 405);
                break;

            case Dispatcher::FOUND:
                [, $handler, $vars] = $routeInfo;

                foreach ($vars as $key => $value) {
                    $request->attributes->set($key, $value);
                }

                $this->container->set(Request::class, $request);

                // 将 DebugBar 的 PDO Collector 重新绑定到当前请求的 PDO（连接池复用时需要）
                if ($this->debugBar !== null && $this->capsule !== null) {
                    $this->bootDebugBarPdo();
                }

                $core = $handler;
                break;
        }

        // 全局中间件洋葱
        $pipeline = $core;
        foreach (array_reverse($this->globalMiddleware) as $mw) {
            $next = $pipeline;
            $pipeline = function () use ($mw, $next, $request) {
                return $mw($request, $next);
            };
        }

        $result = $pipeline();

        if ($result instanceof Response) {
            return $result;
        }
        return new Response((string) $result);
    }

    /**
     * 将中间件按洋葱模型包裹核心处理器。
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

        foreach (array_reverse($middleware) as $mw) {
            $next = $core;
            $core = function () use ($mw, $next) {
                return $mw($this->container->get(Request::class), $next);
            };
        }

        return $core;
    }

    /**
     * 启动 Laravel Eloquent ORM Capsule。
     */
    protected function bootEloquent(array $config): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection($config);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

    /**
     * 将 DebugBar PDO Collector 挂载到 Eloquent 的底层 PDO 连接上，
     * 以便捕获所有 SQL 查询。
     */
    protected function bootDebugBarPdo(): void
    {
        $connection = $this->capsule->getConnection();
        $pdo = $connection->getPdo();

        // 如果已经是 TraceablePDO 则跳过
        if ($pdo instanceof TraceablePDO) {
            return;
        }

        $traceablePdo = new TraceablePDO($pdo);
        $connection->setPdo($traceablePdo);

        // 重置或添加 PDO Collector
        $collector = $this->debugBar->getCollector('pdo');
        if ($collector instanceof PDOCollector) {
            // 已存在：替换内部 PDO
            $ref = new \ReflectionProperty(PDOCollector::class, 'pdo');
            $ref->setAccessible(true);
            $ref->setValue($collector, $traceablePdo);
        } else {
            $this->debugBar->addCollector(new PDOCollector($traceablePdo));
        }
    }
}
