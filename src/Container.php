<?php

declare(strict_types=1);

namespace Matrix;

use Closure;
use ReflectionClass;
use ReflectionParameter;

class Container
{
    /** @var array<string, array{concrete: string|Closure, shared: bool}> */
    protected array $bindings = [];

    /** @var array<string, object> */
    protected array $instances = [];

    /** @var array<string, \ReflectionClass> */
    protected array $reflections = [];

    /**
     * 注册一个普通绑定，每次 make 都会创建新实例。
     */
    public function bind(string $abstract, string|Closure $concrete, bool $shared = false): void
    {
        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    /**
     * 注册单例绑定，每次 make 返回同一个实例。
     */
    public function singleton(string $abstract, string|Closure|null $concrete = null): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }
        $this->bind($abstract, $concrete, true);
    }

    /**
     * 从容器中解析一个依赖。
     */
    public function make(string $abstract): object
    {
        // 已解析的单例直接返回
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->bindings[$abstract]['concrete'] ?? $abstract;
        $shared   = $this->bindings[$abstract]['shared'] ?? false;

        // Closure 绑定
        if ($concrete instanceof Closure) {
            $object = $concrete($this);
        } else {
            $object = $this->resolveClass($concrete);
        }

        if ($shared) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * 利用反射自动递归解析构造函数依赖。
     */
    protected function resolveClass(string $class): object
    {
        $reflection = $this->reflections[$class] ??= new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException(
                sprintf('无法实例化 %s，请先通过 bind() 或 singleton() 绑定到具体实现', $class)
            );
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $dependencies = array_map(
            fn(ReflectionParameter $param): object => $this->resolveParameter($param),
            $constructor->getParameters()
        );

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * 解析单个参数：优先按类型提示自动解析。
     */
    protected function resolveParameter(ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        // 参数无类型声明或类型是内置类型时，尝试使用参数默认值
        if ($type === null || $type->isBuiltin()) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            throw new \RuntimeException(
                sprintf('无法解析参数 $%s，请为其提供默认值', $param->getName())
            );
        }

        /** @var \ReflectionNamedType $type */
        return $this->make($type->getName());
    }
}
