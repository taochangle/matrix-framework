<?php

declare(strict_types=1);

namespace Matrix\Http;

class Response
{
    protected int $statusCode = 200;

    /** @var array<string, string> */
    protected array $headers = [];

    protected string $content = '';

    /**
     * 设置 HTTP 状态码。
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * 获取 HTTP 状态码。
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 设置响应头。
     */
    public function setHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * 获取所有响应头。
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 设置响应体内容。
     */
    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * 获取响应体内容。
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * 创建 JSON 响应。
     */
    public static function json(mixed $data, int $statusCode = 200, int $flags = JSON_UNESCAPED_UNICODE): self
    {
        $response = new self();
        $response->setStatusCode($statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setContent(json_encode($data, $flags));
        return $response;
    }

    /**
     * 发送响应到浏览器。
     */
    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $key => $value) {
            header(sprintf('%s: %s', $key, $value));
        }

        echo $this->content;
    }
}
