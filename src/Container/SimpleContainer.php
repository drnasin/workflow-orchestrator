<?php

namespace WorkflowOrchestrator\Container;

use WorkflowOrchestrator\Contracts\ContainerInterface;
use WorkflowOrchestrator\Exceptions\ContainerException;

class SimpleContainer implements ContainerInterface
{
    private array $instances = [];
    private array $factories = [];

    public function set(string $id, object|callable $value): void
    {
        if ($value instanceof \Closure) {
            $this->factories[$id] = $value;
        } else {
            $this->instances[$id] = $value;
        }
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $instance = ($this->factories[$id])();
            $this->instances[$id] = $instance;
            return $instance;
        }

        // Try to auto-resolve
        if (class_exists($id) && $this->canAutoResolve($id)) {
            $this->instances[$id] = new $id();
            return $this->instances[$id];
        }

        throw new ContainerException("Service not found: $id");
    }

    /**
     * @throws \ReflectionException
     */
    private function canAutoResolve(string $className): bool
    {
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return true;
        } // No constructor = OK

        // All parameters must have defaults
        foreach ($constructor->getParameters() as $param) {
            if (!$param->isDefaultValueAvailable()) {
                return false; // Required parameter = Cannot auto-resolve
            }
        }
        return true;
    }

    // src/Container/SimpleContainer.php

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || (class_exists($id) && $this->canAutoResolve($id));
    }

}