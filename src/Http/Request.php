<?php

declare(strict_types=1);

namespace Matrix\Http;

class Request
{
    /** @var array<string, string> */
    protected array $routeParams = [];

    /** @var array<string, mixed>|null */
    protected ?array $jsonCache = null;

    protected bool $jsonParsed = false;

    /**
     * 获取请求方法（GET / POST 等）。
     */
    public function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * 获取请求路径。
     */
    public function getPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $position = strpos($uri, '?');
        if ($position !== false) {
            $uri = substr($uri, 0, $position);
        }
        return $uri;
    }

    /**
     * 获取 Query 参数。
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * 获取 POST 参数。
     */
    public function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * 获取所有 Query 参数。
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $_GET;
    }

    /**
     * 获取原始请求体。
     */
    public function getBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * 解析 JSON 请求体，支持按 key 取值（只解析一次，结果缓存）。
     */
    public function json(string $key = null, mixed $default = null): mixed
    {
        if (!$this->jsonParsed) {
            $contentType = $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $body = $this->getBody();
                $this->jsonCache = json_decode($body, true);
            }
            $this->jsonParsed = true;
        }

        if ($key === null) {
            return $this->jsonCache;
        }

        return $this->jsonCache[$key] ?? $default;
    }

    /**
     * 设置路由参数。
     *
     * @param array<string, string> $params
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * 获取单个路由参数。
     */
    public function route(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * 获取所有路由参数。
     *
     * @return array<string, string>
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }
}
