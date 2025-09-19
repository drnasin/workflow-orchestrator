<?php

namespace WorkflowOrchestrator\Registry;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use WorkflowOrchestrator\Attributes\Handler;
use WorkflowOrchestrator\Attributes\Orchestrator;
use WorkflowOrchestrator\Exceptions\WorkflowException;

class HandlerRegistry
{
    private array $orchestrators = [];
    private array $handlers = [];

    /**
     * @throws ReflectionException
     */
    public function registerClass(string|object $class): void
    {
        $className = is_string($class) ? $class : get_class($class);
        $reflection = new ReflectionClass($className);

        foreach ($reflection->getMethods() as $method) {
            $this->registerOrchestrators($method, $className);
            $this->registerHandlers($method, $className);
        }
    }

    private function registerOrchestrators(ReflectionMethod $method, string $className): void
    {
        $attributes = $method->getAttributes(Orchestrator::class);

        foreach ($attributes as $attribute) {
            $orchestrator = $attribute->newInstance();
            $this->orchestrators[$orchestrator->channel] = [
                'class'  => $className,
                'method' => $method->getName(),
                'async'  => $orchestrator->async,
            ];
        }
    }

    private function registerHandlers(ReflectionMethod $method, string $className): void
    {
        $attributes = $method->getAttributes(Handler::class);

        foreach ($attributes as $attribute) {
            $handler = $attribute->newInstance();
            $this->handlers[$handler->channel] = [
                'class'          => $className,
                'method'         => $method->getName(),
                'async'          => $handler->async,
                'returnsHeaders' => $handler->returnsHeaders,
            ];
        }
    }

    /**
     * @throws WorkflowException
     */
    public function getOrchestrator(string $channel): array
    {
        return $this->orchestrators[$channel] ?? throw new WorkflowException("No orchestrator found for channel: $channel");
    }

    /**
     * @throws WorkflowException
     */
    public function getHandler(string $channel): array
    {
        return $this->handlers[$channel] ?? throw new WorkflowException("No handler found for channel: $channel");
    }

    public function hasOrchestrator(string $channel): bool
    {
        return isset($this->orchestrators[$channel]);
    }

    public function hasHandler(string $channel): bool
    {
        return isset($this->handlers[$channel]);
    }
}