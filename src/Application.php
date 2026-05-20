<?php

declare(strict_types=1);

namespace Matrix;

use Closure;
use Matrix\Http\Request;
use Matrix\Http\Response;
use ReflectionFunction;
use ReflectionMethod;

class Application extends Container
{
    protected static ?self $instance = null;

    protected Router $router;

    /** @var array<callable> */
    protected array $globalMiddleware = [];

    public function __construct()
    {
        self::$instance = $this;

        $this->singleton(Router::class);

        $this->router = $this->make(Router::class);
    }

    /**
     * 获取 Application 单例。
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取路由收集器。
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * 注册全局中间件。
     *
     * @param array<callable> $middleware
     */
    public function addGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = array_merge($this->globalMiddleware, $middleware);
    }

    /**
     * 加载路由文件。
     */
    public function loadRoutes(string $file): void
    {
        $router = $this->router;
        require $file;
    }

    /**
     * 处理 HTTP 请求并发送响应。
     */
    public function handle(Request $request): void
    {
        try {
            $pipeline = new Pipeline();

            $response = $pipeline
                ->send($request)
                ->through($this->globalMiddleware)
                ->then(function (Request $request): Response {
                    return $this->router->dispatch($request, $this);
                });
        } catch (\Throwable $e) {
            $response = Response::json([
                'code'    => -1,
                'message' => $e->getMessage(),
            ], 500);
        }

        $response->send();
    }

    /**
     * 调用闭包，自动解析参数。
     */
    public function callClosure(Closure $closure, Request $request): Response
    {
        $reflection = new ReflectionFunction($closure);
        $args = $this->resolveCallableParameters($reflection->getParameters(), $request);
        return $reflection->invokeArgs($args);
    }

    /**
     * 调用对象方法，自动解析参数。
     */
    public function callMethod(object $instance, string $method, Request $request): Response
    {
        $reflection = new ReflectionMethod($instance, $method);
        $args = $this->resolveCallableParameters($reflection->getParameters(), $request);
        return $reflection->invokeArgs($instance, $args);
    }

    /**
     * 解析调用参数：自动注入 Request 或从容器解析类型提示。
     *
     * @param \ReflectionParameter[] $parameters
     * @return array<int, mixed>
     */
    protected function resolveCallableParameters(array $parameters, Request $request): array
    {
        return array_map(function (\ReflectionParameter $param) use ($request): mixed {
            $type = $param->getType();

            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
                if ($typeName === Request::class) {
                    return $request;
                }
                if (!$type->isBuiltin()) {
                    return $this->make($typeName);
                }
            }

            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            throw new \RuntimeException(
                sprintf('无法解析参数 $%s', $param->getName())
            );
        }, $parameters);
    }
}
