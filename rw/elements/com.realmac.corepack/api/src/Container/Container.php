<?php

namespace App\Container;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

/**
 * Simple dependency injection container
 */
class Container
{
    private array $bindings = [];
    private array $instances = [];
    private array $singletons = [];

    /**
     * Bind a service to the container
     */
    public function bind(string $abstract, callable|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Bind a singleton to the container
     */
    public function singleton(string $abstract, callable|string $concrete): void
    {
        $this->bind($abstract, $concrete);
        $this->singletons[$abstract] = true;
    }

    /**
     * Register an existing instance
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = true;
    }

    /**
     * Make an instance of the given type
     */
    public function make(string $abstract): mixed
    {
        // Return existing instance if singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Get the concrete implementation
        $concrete = $this->getConcrete($abstract);

        // Build the instance
        $instance = $this->build($concrete);

        // Store as singleton if needed
        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Check if the container has a binding
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Get the concrete implementation
     */
    private function getConcrete(string $abstract): callable|string
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract];
        }

        // If no binding exists, assume the abstract is the concrete
        return $abstract;
    }

    /**
     * Build an instance of the given type
     */
    private function build(callable|string $concrete): mixed
    {
        // If concrete is a callable, call it
        if (is_callable($concrete)) {
            return $concrete($this);
        }

        // If concrete is a string, try to instantiate it
        if (is_string($concrete)) {
            return $this->buildClass($concrete);
        }

        throw new InvalidArgumentException("Invalid concrete type");
    }

    /**
     * Build a class instance with dependency injection
     */
    private function buildClass(string $className): object
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException("Class {$className} does not exist");
        }

        // If the class is not instantiable, throw an exception
        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException("Class {$className} is not instantiable");
        }

        $constructor = $reflection->getConstructor();

        // If there's no constructor, just create the instance
        if (!$constructor) {
            return $reflection->newInstance();
        }

        // Resolve constructor dependencies
        $dependencies = $this->resolveDependencies($constructor->getParameters());

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve method dependencies
     */
    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if (!$type || $type->isBuiltin()) {
                // Handle primitive types or no type hint
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new InvalidArgumentException(
                        "Cannot resolve parameter {$parameter->getName()}"
                    );
                }
            } else {
                // Handle class dependencies
                $className = $type->getName();
                $dependencies[] = $this->make($className);
            }
        }

        return $dependencies;
    }

    /**
     * Call a method with dependency injection
     */
    public function call(callable $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            [$class, $method] = $callback;
            $reflection = new ReflectionClass($class);
            $methodReflection = $reflection->getMethod($method);
            $dependencies = $this->resolveDependencies($methodReflection->getParameters());
        } else {
            // For closures, we can't easily resolve dependencies
            $dependencies = [];
        }

        return call_user_func_array($callback, array_merge($dependencies, $parameters));
    }
}
