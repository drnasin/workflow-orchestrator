<?php

namespace WorkflowOrchestrator;

use WorkflowOrchestrator\Container\SimpleContainer;
use WorkflowOrchestrator\Contracts\ContainerInterface;
use WorkflowOrchestrator\Contracts\QueueInterface;
use WorkflowOrchestrator\Engine\WorkflowEngine;
use WorkflowOrchestrator\Exceptions\WorkflowException;

/**
 * Main facade class for easy usage
 */
class WorkflowOrchestrator
{
    private WorkflowEngine $engine;

    public function __construct(
        ?ContainerInterface $container = null, ?QueueInterface $queue = null, array $middleware = []
    ) {
        $container ??= new SimpleContainer();
        $this->engine = new WorkflowEngine($container, queue: $queue, middleware: $middleware);
    }

    /**
     * Static factory for quick setup
     */
    public static function create(): self
    {
        return new self();
    }

    public function register(string|object $class): self
    {
        $this->engine->register($class);
        return $this;
    }

    /**
     * @throws \ReflectionException
     * @throws WorkflowException
     */
    public function execute(string $channel, mixed $payload, array $headers = []): mixed
    {
        return $this->engine->execute($channel, $payload, $headers);
    }

    /**
     * @throws WorkflowException
     */
    public function processAsyncStep(string $stepName): void
    {
        $this->engine->processAsyncStep($stepName);
    }

    public function getEngine(): WorkflowEngine
    {
        return $this->engine;
    }
}