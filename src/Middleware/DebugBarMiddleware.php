<?php

declare(strict_types=1);

namespace Matrix\Middleware;

use Closure;
use DebugBar\StandardDebugBar;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DebugBarMiddleware
{
    protected StandardDebugBar $debugBar;

    public function __construct(StandardDebugBar $debugBar)
    {
        $this->debugBar = $debugBar;
    }

    public function __invoke(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $content = $response->getContent();

        // 判断是否为 HTML 响应（内容以 < 或 <!DOCTYPE 开头）
        if (str_starts_with(ltrim($content), '<')) {
            $response = $this->injectDebugBarHtml($response);
        } else {
            // API / JSON 响应：通过 Header 传递 DebugBar ID
            $response = $this->injectDebugBarHeaders($response);
        }

        return $response;
    }

    /**
     * HTML 响应：将 DebugBar 注入到 </body> 之前。
     */
    protected function injectDebugBarHtml(Response $response): Response
    {
        $renderer = $this->debugBar->getJavascriptRenderer();
        $renderer->setBaseUrl('/_debugbar/assets');
        $debugBarHtml  = $renderer->renderHead();
        $debugBarHtml .= $renderer->render();

        $content = $response->getContent();

        if (stripos($content, '</body>') !== false) {
            $content = str_ireplace('</body>', $debugBarHtml . '</body>', $content);
        } else {
            $content .= $debugBarHtml;
        }

        $response->setContent($content);
        return $response;
    }

    /**
     * API / JSON 响应：通过 HTTP Header 传递 DebugBar ID。
     */
    protected function injectDebugBarHeaders(Response $response): Response
    {
        $response->headers->set('X-DebugBar-Id', $this->debugBar->getCurrentRequestId());
        return $response;
    }
}
