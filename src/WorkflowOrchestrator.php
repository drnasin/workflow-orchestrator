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
        private ?ContainerInterface $container = null, 
        private readonly ?QueueInterface $queue = null,
        private readonly array $middleware = []
    ) {
        $this->container ??= new SimpleContainer();
        $this->engine = new WorkflowEngine($this->container, queue: $this->queue, middleware: $this->middleware);
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

    public function withQueue(QueueInterface $queue): self
    {
        return new self($this->container, $queue, $this->middleware);
    }

    public function getEngine(): WorkflowEngine
    {
        return $this->engine;
    }
}