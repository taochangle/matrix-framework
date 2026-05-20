<?php

declare(strict_types=1);

namespace Matrix\Console;

use Closure;
use Matrix\Application;

class Console
{
    /** @var array<string, array{handler: Closure, description: string}> */
    protected array $commands = [];

    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function register(string $name, Closure $handler, string $description = ''): void
    {
        $this->commands[$name] = compact('handler', 'description');
    }

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($commandName === 'help') {
            return $this->showHelp();
        }

        if (!isset($this->commands[$commandName])) {
            echo sprintf("未知命令: %s\n", $commandName);
            return $this->showHelp();
        }

        return ($this->commands[$commandName]['handler'])($args, $this->app);
    }

    protected function showHelp(): int
    {
        echo "Matrix Console\n\n";
        echo "可用命令:\n";
        foreach ($this->commands as $name => $cmd) {
            printf("  %-24s %s\n", $name, $cmd['description']);
        }
        printf("  %-24s %s\n", 'help', '显示帮助信息');
        return 0;
    }
}
