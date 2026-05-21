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

        $contentType = $response->headers->get('Content-Type', '');

        // 判断是否为 HTML 响应
        if (stripos($contentType, 'text/html') !== false) {
            $response = $this->injectDebugBarHtml($response);
        } else {
            // API / JSON 响应：通过 Header 传递 Trace 数据
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
     * API / JSON 响应：通过 HTTP Header 发送 Trace 数据。
     */
    protected function injectDebugBarHeaders(Response $response): Response
    {
        $renderer = $this->debugBar->getJavascriptRenderer();
        $renderer->sendDataInHeaders(true);

        // 触发 header 收集
        ob_start();
        $renderer->render();
        ob_end_clean();

        // sendDataInHeaders 会在标准输出上产生 header，需要手动获取
        // DebugBar 的 sendDataInHeaders 通过 header() 函数发送
        // 在非标准输出（如这里）需要自己处理
        $openHandlerUrl = '/_debugbar/open';
        $response->headers->set('phpdebugbar-id', $this->debugBar->getCurrentRequestId());
        $response->headers->set('phpdebugbar-control', json_encode([
            'id'   => $this->debugBar->getCurrentRequestId(),
            'url'  => $openHandlerUrl,
        ]));

        return $response;
    }
}
