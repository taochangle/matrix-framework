<?php

declare(strict_types=1);

namespace Matrix\View;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Twig
{
    protected Environment $twig;

    public function __construct(string $templatePath)
    {
        $loader = new FilesystemLoader($templatePath);
        $this->twig = new Environment($loader, [
            'cache'       => $templatePath . '/../storage/cache/twig',
            'auto_reload' => true,
            'debug'       => true,
        ]);
    }

    /**
     * 渲染模板并返回 Response。
     */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        $html = $this->twig->render($template, $data);
        return new Response($html, $status);
    }

    /**
     * 渲染模板为字符串。
     */
    public function renderToString(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    public function getEnvironment(): Environment
    {
        return $this->twig;
    }
}
