<?php

namespace WorkflowOrchestrator;

use WorkflowOrchestrator\Container\SimpleContainer;
use WorkflowOrchestrator\Contracts\ContainerInterface;
use WorkflowOrchestrator\Contracts\EventListenerInterface;
use WorkflowOrchestrator\Contracts\MiddlewareInterface;
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
        private readonly array $middleware = [],
        private readonly array $eventListeners = [],
    ) {
        $this->container ??= new SimpleContainer();
        $this->engine = new WorkflowEngine(
            $this->container,
            queue: $this->queue,
            middleware: $this->middleware,
            eventListeners: $this->eventListeners,
        );
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
     * @param callable|null $backoff   Optional `function(int $retryAttempt): int|float` returning the
     *                                 seconds to wait before re-queuing a failed attempt. Default `null`
     *                                 means no wait. See {@see WorkflowEngine::exponentialBackoff()}.
     * @param string|null   $dlqQueue  Optional dead-letter queue name. When set, a message that has
     *                                 exhausted all retries is pushed there and no exception is thrown.
     *
     * @throws WorkflowException
     */
    public function processAsyncStep(
        string $stepName,
        int $maxRetries = 3,
        ?callable $backoff = null,
        ?string $dlqQueue = null,
    ): void {
        $this->engine->processAsyncStep($stepName, $maxRetries, $backoff, $dlqQueue);
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        return new self($this->container, $this->queue, [...$this->middleware, $middleware], $this->eventListeners);
    }

    public function withEventListener(EventListenerInterface $listener): self
    {
        return new self($this->container, $this->queue, $this->middleware, [...$this->eventListeners, $listener]);
    }

    public function withQueue(QueueInterface $queue): self
    {
        return new self($this->container, $queue, $this->middleware, $this->eventListeners);
    }

    public function getEngine(): WorkflowEngine
    {
        return $this->engine;
    }
}