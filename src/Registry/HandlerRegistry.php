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
     * @throws WorkflowException if a channel is already registered to a different method
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
            $config = [
                'class'  => $className,
                'method' => $method->getName(),
            ];
            $this->guardAgainstChannelConflict($this->orchestrators, $orchestrator->channel, $config, 'Orchestrator');
            $this->orchestrators[$orchestrator->channel] = $config;
        }
    }

    private function registerHandlers(ReflectionMethod $method, string $className): void
    {
        $attributes = $method->getAttributes(Handler::class);

        foreach ($attributes as $attribute) {
            $handler = $attribute->newInstance();
            $config = [
                'class'          => $className,
                'method'         => $method->getName(),
                'async'          => $handler->async,
                'returnsHeaders' => $handler->returnsHeaders,
                'timeout'        => $handler->timeout,
            ];
            $this->guardAgainstChannelConflict($this->handlers, $handler->channel, $config, 'Handler');
            $this->handlers[$handler->channel] = $config;
        }
    }

    /**
     * Rejects two different methods claiming the same channel. Re-registering the
     * exact same class+method is idempotent and allowed; a conflicting registration
     * is a configuration error that would otherwise be silently overwritten.
     *
     * @throws WorkflowException
     */
    private function guardAgainstChannelConflict(array $existing, string $channel, array $config, string $kind): void
    {
        if (!isset($existing[$channel])) {
            return;
        }

        $current = $existing[$channel];
        if ($current['class'] !== $config['class'] || $current['method'] !== $config['method']) {
            throw new WorkflowException(
                "$kind channel '$channel' is already registered to {$current['class']}::{$current['method']}; "
                . "cannot re-register it to {$config['class']}::{$config['method']}"
            );
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