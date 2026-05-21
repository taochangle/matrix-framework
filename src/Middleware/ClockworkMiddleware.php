<?php

declare(strict_types=1);

namespace Matrix\Middleware;

use Clockwork\Clockwork;
use Clockwork\Request\Request as ClockworkRequest;
use Closure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ClockworkMiddleware
{
    protected Clockwork $clockwork;

    public function __construct(Clockwork $clockwork)
    {
        $this->clockwork = $clockwork;
    }

    public function __invoke(Request $request, Closure $next): Response
    {
        $this->clockwork->requestProcessed();

        /** @var Response $response */
        $response = $next($request);

        // Clockwork 收集数据并追加响应头
        $clockworkRequest = $this->clockwork->resolveRequest();
        if ($clockworkRequest instanceof ClockworkRequest) {
            $response->headers->set('X-Clockwork-Id', $clockworkRequest->id);
            $response->headers->set('X-Clockwork-Version', Clockwork::VERSION);
            $response->headers->set('X-Clockwork-Path', '/__clockwork/');
        }

        return $response;
    }
}
