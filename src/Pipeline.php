<?php

declare(strict_types=1);

namespace Matrix;

use Closure;
use Matrix\Http\Request;
use Matrix\Http\Response;

class Pipeline
{
    /** @var Request */
    protected Request $request;

    /** @var array<callable> */
    protected array $middleware = [];

    /**
     * 设置要流经的请求。
     */
    public function send(Request $request): self
    {
        $this->request = $request;
        return $this;
    }

    /**
     * 设置中间件栈。
     *
     * @param array<callable> $middleware
     */
    public function through(array $middleware): self
    {
        $this->middleware = $middleware;
        return $this;
    }

    /**
     * 依次执行中间件，最终调用目标处理器。
     *
     * @param Closure(Request): Response $destination
     */
    public function then(Closure $destination): Response
    {
        $core = $destination;

        // 洋葱模型：从最外层中间件开始逐层包裹
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function (Closure $next, callable $middleware): Closure {
                return function (Request $request) use ($middleware, $next): Response {
                    return $middleware($request, $next);
                };
            },
            $core
        );

        return $pipeline($this->request);
    }
}
