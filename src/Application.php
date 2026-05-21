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
use Matrix\Logger\Logger;
use Matrix\Middleware\DebugBarMiddleware;
use Matrix\Routing\RouteCollector;
use Matrix\View\Twig;
use PDO;
use Spatie\Ignition\Ignition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Application
{
    public const NAME    = 'Matrix Framework';
    public const VERSION = '1.0.0';

    protected Container $container;

    /** @var array<callable> */
    protected array $routes = [];

    /** @var array<callable> */
    protected array $globalMiddleware = [];

    protected ?StandardDebugBar $debugBar = null;

    protected ?Logger $logger = null;

    protected ?Capsule $capsule = null;

    /**
     * @param array<string, mixed> $options {
     *     database?: array,   // Eloquent 数据库配置
     *     debug?: bool,       // 是否启用 Ignition 错误页
     * }
     */
    public function __construct(array $options = [])
    {
        $debug = $options['debug'] ?? ($_ENV['APP_DEBUG'] ?? 'true') === 'true';

        // Spatie Ignition（版本信息通过 Flare 上报 + 环境变量展示）
        if ($debug) {
            $_ENV['MATRIX_VERSION'] = self::VERSION;

            $ignition = Ignition::make()->applicationPath(dirname(__DIR__));

            // 错误页顶部 Documentation 链接（含版本号）
            $ignition->resolveDocumentationLink(
                fn() => 'https://github.com/taochangle/matrix-framework (Matrix Framework v' . self::VERSION . ')'
            );

            $ignition->getFlare()->determineVersionUsing(fn() => 'Matrix Framework v' . self::VERSION);
            $ignition->register();
        }

        // 初始化 PHP-DI 容器
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $this->container = $builder->build();

        // 启动 Eloquent ORM
        if (isset($options['database'])) {
            $this->bootEloquent($options['database']);
        }

        // DebugBar：仅 debug 模式启用
        if ($debug) {
            $this->debugBar = new StandardDebugBar();
            $this->container->set(StandardDebugBar::class, $this->debugBar);

            // Logger：写入 storage/logs/matrix.log + DebugBar 消息面板
            $this->logger = new Logger($options['log_path'] ?? dirname(__DIR__, 2) . '/storage/logs/matrix.log');
            $this->logger->setDebugBar($this->debugBar);
            $this->logger->info(self::NAME . ' v' . self::VERSION . ' started');
            $this->container->set(Logger::class, $this->logger);

            // Twig 模板引擎
            $viewsPath = $options['views_path'] ?? dirname(__DIR__, 2) . '/views';
            if (is_dir($viewsPath)) {
                $twig = new Twig($viewsPath);
                $this->container->set(Twig::class, $twig);
            }

            if ($this->capsule !== null) {
                $this->bootDebugBarPdo();
            }

            $this->addGlobalMiddleware([new DebugBarMiddleware($this->debugBar)]);
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getDebugBar(): ?StandardDebugBar
    {
        return $this->debugBar;
    }

    public function getLogger(): ?Logger
    {
        return $this->logger;
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
        $db = $this->debugBar;
        $time = $db?->offsetGet('time');

        // Timeline: 构建路由表
        $time?->startMeasure('route_build', 'Build Routes');
        $dispatcher = \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
            foreach ($this->routes as $route) {
                $handler = $this->wrapMiddleware($route['handler'], $route['middleware']);
                $r->addRoute($route['method'], $route['uri'], $handler);
            }
        });
        if ($time?->hasStartedMeasure('route_build')) $time?->stopMeasure('route_build');

        // Timeline: 路由分发
        $time?->startMeasure('route_dispatch', 'Dispatch');
        $routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getPathInfo());
        if ($time?->hasStartedMeasure('route_dispatch')) $time?->stopMeasure('route_dispatch');

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

                if ($this->debugBar !== null && $this->capsule !== null) {
                    $this->bootDebugBarPdo();
                }

                // Timeline: 控制器执行
                $core = function () use ($handler, $time) {
                    $time?->startMeasure('handler', 'Controller');
                    $result = $handler();
                    if ($time?->hasStartedMeasure('handler')) $time?->stopMeasure('handler');
                    return $result;
                };

                $this->logger?->info($request->getMethod() . ' ' . $request->getPathInfo());
                break;
        }

        // Timeline: 全局中间件
        $time?->startMeasure('middleware', 'Middleware');
        $pipeline = $core;
        foreach (array_reverse($this->globalMiddleware) as $mw) {
            $next = $pipeline;
            $pipeline = function () use ($mw, $next, $request) {
                return $mw($request, $next);
            };
        }
        $result = $pipeline();
        if ($time?->hasStartedMeasure('middleware')) $time?->stopMeasure('middleware');

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

        // 添加 PDO Collector（如已存在则替换内部 PDO）
        if ($this->debugBar->hasCollector('pdo')) {
            $collector = $this->debugBar->getCollector('pdo');
            if ($collector instanceof PDOCollector) {
                $ref = new \ReflectionProperty(PDOCollector::class, 'pdo');
                $ref->setAccessible(true);
                $ref->setValue($collector, $traceablePdo);
            }
        } else {
            $this->debugBar->addCollector(new PDOCollector($traceablePdo));
        }
    }
}
