<?php

namespace WorkflowOrchestrator\Engine;

use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use WorkflowOrchestrator\Attributes\Header;
use WorkflowOrchestrator\Contracts\ContainerInterface;
use WorkflowOrchestrator\Contracts\EventListenerInterface;
use WorkflowOrchestrator\Contracts\QueueInterface;
use WorkflowOrchestrator\Exceptions\WorkflowException;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\Queue\InMemoryQueue;
use WorkflowOrchestrator\Registry\HandlerRegistry;

readonly class WorkflowEngine
{
    private QueueInterface $queue;

    public function __construct(
        private ContainerInterface $container,
        private HandlerRegistry $registry = new HandlerRegistry(),
        ?QueueInterface $queue = null,
        private array $middleware = [],
        private array $eventListeners = [],
    ) {
        $this->queue = $queue ?? new InMemoryQueue();
    }

    public function register(string|object $class): self
    {
        $this->registry->registerClass($class);
        return $this;
    }

    /**
     * @throws WorkflowException
     * @throws \ReflectionException
     */
    public function execute(string $channel, mixed $payload, array $headers = []): mixed
    {
        if (!$this->registry->hasOrchestrator($channel)) {
            throw new WorkflowException("No orchestrator registered for channel: $channel");
        }

        $orchestratorConfig = $this->registry->getOrchestrator($channel);
        $instance = $this->container->get($orchestratorConfig['class']);

        // Execute orchestrator to get workflow steps
        $steps = $this->invokeMethod($instance, $orchestratorConfig['method'], $payload);

        if (!is_array($steps)) {
            throw new WorkflowException("Orchestrator must return an array of steps");
        }

        // Create workflow message
        $message = new WorkflowMessage($payload, $steps, $headers);

        // Process workflow
        return $this->processWorkflow($message);
    }

    /**
     * @throws \ReflectionException
     */
    private function invokeMethod(object $instance, string $methodName, mixed $payload, ?WorkflowMessage $message = null): mixed
    {
        $reflection = new ReflectionMethod($instance, $methodName);
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            $args[] = $this->resolveParameter($param, $payload, $message);
        }

        return $reflection->invoke($instance, ...$args);
    }

    private function resolveParameter(ReflectionParameter $param, mixed $payload, ?WorkflowMessage $message): mixed
    {
        // Check for Header attribute
        $headerAttributes = $param->getAttributes(Header::class);
        if (!empty($headerAttributes) && $message) {
            $headerName = $headerAttributes[0]->newInstance()->name;
            $defaultValue = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
            return $message->getHeader($headerName, $defaultValue);
        }

        // Try to resolve from container
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            // Check if payload matches the type
            if ($payload instanceof $typeName) {
                return $payload;
            }

            if ($this->container->has($typeName)) {
                return $this->container->get($typeName);
            }

            throw new WorkflowException(
                "Cannot resolve parameter '\${$param->getName()}' of type '{$typeName}': "
                . "payload is not an instance of {$typeName} and no container binding found"
            );
        }

        // Return payload as fallback for builtin/untyped parameters
        return $payload;
    }

    /**
     * @throws WorkflowException
     */
    public function processWorkflow(WorkflowMessage $message): mixed
    {
        $message = $this->applyMiddleware($message);

        while ($message->hasMoreSteps()) {
            $stepName = $message->getNextStep();
            $message = $message->withoutFirstStep();

            if (!$this->registry->hasHandler($stepName)) {
                throw new WorkflowException("No handler registered for step: $stepName");
            }

            $handlerConfig = $this->registry->getHandler($stepName);

            if ($handlerConfig['async']) {
                // Queue for async processing
                $this->queue->push($stepName, $message);
                return null; // Async processing doesn't return immediately
            }

            $message = $this->executeStep($stepName, $message);
        }

        return $message->getPayload();
    }

    private function applyMiddleware(WorkflowMessage $message): WorkflowMessage
    {
        $pipeline = array_reduce(array_reverse($this->middleware),
            fn($next, $middleware) => fn($msg) => $middleware->handle($msg, $next), fn($msg) => $msg);

        return $pipeline($message);
    }

    /**
     * @throws WorkflowException
     */
    private function executeStep(string $stepName, WorkflowMessage $message): WorkflowMessage
    {
        $handlerConfig = $this->registry->getHandler($stepName);
        $instance = $this->container->get($handlerConfig['class']);
        $timeout = $handlerConfig['timeout'] ?? 0;

        $this->notifyStepStarted($stepName, $message);
        $startTime = hrtime(true);

        try {
            $result = $this->invokeMethod($instance, $handlerConfig['method'], $message->getPayload(), $message);
            $duration = (hrtime(true) - $startTime) / 1e9;

            if ($timeout > 0 && $duration > $timeout) {
                $exception = new WorkflowException(
                    "Step '$stepName' exceeded timeout of {$timeout}s (took " . round($duration, 2) . "s)",
                    0, null, $stepName
                );
                $this->notifyStepFailed($stepName, $message, $exception, $duration);
                throw $exception;
            }

            if ($handlerConfig['returnsHeaders']) {
                $resultMessage = $message->withHeaders(is_array($result) ? $result : []);
            } else {
                $resultMessage = $message->withPayload($result);
            }

            $this->notifyStepCompleted($stepName, $resultMessage, $duration);

            return $resultMessage;
        } catch (WorkflowException $e) {
            // Re-throw WorkflowExceptions (including timeout) without wrapping
            if ($e->getFailedStep() !== null) {
                throw $e;
            }
            $duration = (hrtime(true) - $startTime) / 1e9;
            $this->notifyStepFailed($stepName, $message, $e, $duration);
            throw new WorkflowException("Step '$stepName' failed: " . $e->getMessage(), 0, $e, $stepName);
        } catch (Throwable $e) {
            $duration = (hrtime(true) - $startTime) / 1e9;
            $this->notifyStepFailed($stepName, $message, $e, $duration);
            throw new WorkflowException("Step '$stepName' failed: " . $e->getMessage(), 0, $e, $stepName);
        }
    }

    /**
     * @throws WorkflowException
     */
    public function processAsyncStep(string $stepName, int $maxRetries = 3): void
    {
        $message = $this->queue->pop($stepName);

        if (!$message) {
            return;
        }

        $attempt = (int) $message->getHeader('_retry_attempt', 0);

        try {
            $message = $this->executeStep($stepName, $message);

            // Continue processing remaining steps if any
            if ($message->hasMoreSteps()) {
                $this->processWorkflow($message);
            }
        } catch (Throwable $e) {
            if ($attempt < $maxRetries) {
                // Re-queue with incremented retry count
                $retryMessage = $message->withHeader('_retry_attempt', $attempt + 1);
                $this->queue->push($stepName, $retryMessage);
                return;
            }

            throw new WorkflowException(
                "Async step '$stepName' failed after " . ($attempt + 1) . " attempt(s): " . $e->getMessage(),
                0, $e, $stepName
            );
        }
    }

    private function notifyStepStarted(string $stepName, WorkflowMessage $message): void
    {
        foreach ($this->eventListeners as $listener) {
            $listener->onStepStarted($stepName, $message);
        }
    }

    private function notifyStepCompleted(string $stepName, WorkflowMessage $message, float $duration): void
    {
        foreach ($this->eventListeners as $listener) {
            $listener->onStepCompleted($stepName, $message, $duration);
        }
    }

    private function notifyStepFailed(string $stepName, WorkflowMessage $message, Throwable $error, float $duration): void
    {
        foreach ($this->eventListeners as $listener) {
            $listener->onStepFailed($stepName, $message, $error, $duration);
        }
    }
}
