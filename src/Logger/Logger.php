<?php

declare(strict_types=1);

namespace Matrix\Logger;

use DebugBar\DataCollector\MessagesCollector;
use DebugBar\StandardDebugBar;

class Logger
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    protected string $logFile;

    protected ?MessagesCollector $debugBarMessages = null;

    /** @var array<string> */
    protected static array $levels = ['debug', 'info', 'warning', 'error'];

    public function __construct(string $logFile)
    {
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->logFile = $logFile;
    }

    /**
     * 关联 DebugBar MessagesCollector，日志同时出现在调试工具栏。
     */
    public function setDebugBar(?StandardDebugBar $debugBar): void
    {
        if ($debugBar !== null && $debugBar->hasCollector('messages')) {
            $collector = $debugBar->getCollector('messages');
            if ($collector instanceof MessagesCollector) {
                $this->debugBarMessages = $collector;
            }
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, self::$levels, true)) {
            $level = self::INFO;
        }

        $line = sprintf(
            '[%s] [%s] %s %s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? json_encode($context) : ''
        );

        error_log($line . PHP_EOL, 3, $this->logFile);

        if ($this->debugBarMessages !== null) {
            $this->debugBarMessages->{$level}($message);
        }
    }
}
