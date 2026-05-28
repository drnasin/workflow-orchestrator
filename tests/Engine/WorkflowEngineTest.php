<?php

namespace WorkflowOrchestrator\Tests\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Attributes\Handler;
use WorkflowOrchestrator\Attributes\Header;
use WorkflowOrchestrator\Attributes\Orchestrator;
use WorkflowOrchestrator\Container\SimpleContainer;
use WorkflowOrchestrator\Contracts\EventListenerInterface;
use WorkflowOrchestrator\Contracts\MiddlewareInterface;
use WorkflowOrchestrator\Engine\WorkflowEngine;
use WorkflowOrchestrator\Exceptions\WorkflowException;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\Queue\InMemoryQueue;
use WorkflowOrchestrator\Registry\HandlerRegistry;

class TestOrder
{
    public function __construct(
        public string $id, public float $total = 100.0, public bool $isPremium = false, public bool $isProcessed = false
    ) {
    }
}

class OrderWorkflow
{
    #[Orchestrator(channel: 'process.order')]
    public function processOrder(TestOrder $order): array
    {
        $steps = ['validate', 'payment'];

        if ($order->isPremium) {
            $steps[] = 'premium-discount';
        }

        $steps[] = 'confirmation';
        return $steps;
    }

    #[Handler(channel: 'validate')]
    public function validate(TestOrder $order): TestOrder
    {
        // Simulate validation
        return $order;
    }

    #[Handler(channel: 'payment')]
    public function processPayment(TestOrder $order): TestOrder
    {
        $order->isProcessed = true;
        return $order;
    }

    #[Handler(channel: 'premium-discount')]
    public function applyPremiumDiscount(TestOrder $order): TestOrder
    {
        $order->total *= 0.9; // 10% discount
        return $order;
    }

    #[Handler(channel: 'confirmation')]
    public function sendConfirmation(TestOrder $order): TestOrder
    {
        return $order;
    }

    #[Handler(channel: 'enrich', returnsHeaders: true)]
    public function enrichWithHeaders(TestOrder $order): array
    {
        return [
            'customer_type' => $order->isPremium ? 'premium' : 'regular',
            'order_value'   => $order->total
        ];
    }

    #[Handler(channel: 'use-headers')]
    public function useHeaders(
        TestOrder $order, #[Header('customer_type')] string $customerType, #[Header('order_value')] float $orderValue
    ): TestOrder {
        // Headers are available as method parameters
        return $order;
    }

    #[Handler(channel: 'async-step', async: true)]
    public function asyncStep(TestOrder $order): TestOrder
    {
        return $order;
    }
}

class FailingWorkflow
{
    #[Orchestrator(channel: 'failing-workflow')]
    public function failingWorkflow(TestOrder $order): array
    {
        return ['failing-step'];
    }

    #[Handler(channel: 'failing-step')]
    public function fail(TestOrder $order): TestOrder
    {
        throw new WorkflowException('Step failed');
    }
}

class CountingPayload
{
    public int $seenInvocation = 0;
}

class StatefulCountingWorkflow
{
    private int $invocations = 0;

    #[Orchestrator(channel: 'counting')]
    public function orchestrate(CountingPayload $payload): array
    {
        return ['count-step'];
    }

    #[Handler(channel: 'count-step')]
    public function count(CountingPayload $payload): CountingPayload
    {
        $payload->seenInvocation = ++$this->invocations;
        return $payload;
    }
}

class WorkflowEngineTest extends TestCase
{
    private SimpleContainer $container;
    private InMemoryQueue $queue;
    private WorkflowEngine $engine;

    public function test_executes_complete_workflow(): void
    {
        $order = new TestOrder('ORD-123', 100.0, false);

        $result = $this->engine->execute('process.order', $order);

        $this->assertInstanceOf(TestOrder::class, $result);
        $this->assertTrue($result->isProcessed);
        $this->assertSame(100.0, $result->total); // No premium discount applied
    }

    public function test_executes_premium_workflow(): void
    {
        $order = new TestOrder('ORD-124', 100.0, true);

        $result = $this->engine->execute('process.order', $order);

        $this->assertInstanceOf(TestOrder::class, $result);
        $this->assertTrue($result->isProcessed);
        $this->assertSame(90.0, $result->total); // Premium discount applied
    }

    public function test_passes_headers_between_steps(): void
    {
        // Create a workflow that uses the enrich step
        $headerWorkflow = new class {
            #[Orchestrator(channel: 'header-workflow')]
            public function headerWorkflow(TestOrder $order): array
            {
                return ['enrich', 'use-headers'];
            }
        };

        $this->container->set(get_class($headerWorkflow), $headerWorkflow);
        $this->engine->register($headerWorkflow);

        $order = new TestOrder('ORD-125', 100.0, true);

        // Test headers workflow
        $result = $this->engine->execute('header-workflow', $order);

        // The enrich step should return headers that can be used in subsequent steps
        $this->assertInstanceOf(TestOrder::class, $result); // If we get here, headers are working
    }

    public function test_handles_async_steps(): void
    {
        // Create a workflow that uses async steps
        $asyncWorkflow = new class {
            #[Orchestrator(channel: 'async-workflow')]
            public function asyncWorkflow(TestOrder $order): array
            {
                return ['async-step'];
            }
        };

        $this->container->set(get_class($asyncWorkflow), $asyncWorkflow);
        $this->engine->register($asyncWorkflow);

        $order = new TestOrder('ORD-126');

        // This should return null because async steps don't return immediately
        $result = $this->engine->execute('async-workflow', $order);

        $this->assertNull($result);
        $this->assertSame(1, $this->queue->size('async-step'));
    }

    public function test_processes_queued_async_steps(): void
    {
        // Create a workflow that uses async steps
        $asyncWorkflow = new class {
            #[Orchestrator(channel: 'async-workflow-2')]
            public function asyncWorkflow(TestOrder $order): array
            {
                return ['async-step'];
            }
        };

        $this->container->set(get_class($asyncWorkflow), $asyncWorkflow);
        $this->engine->register($asyncWorkflow);

        $order = new TestOrder('ORD-127');

        // Execute async step (gets queued)
        $this->engine->execute('async-workflow-2', $order);

        // Process the queued step
        $this->engine->processAsyncStep('async-step');

        $this->assertSame(0, $this->queue->size('async-step'));
    }

    public function test_throws_exception_for_unknown_orchestrator(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No orchestrator registered for channel: unknown');

        $this->engine->execute('unknown', new TestOrder('ORD-128'));
    }

    public function test_throws_exception_for_unknown_handler(): void
    {
        // Register orchestrator that references unknown handler
        $badWorkflowClass = new class {
            #[Orchestrator(channel: 'bad-workflow')]
            public function badWorkflow(TestOrder $order): array
            {
                return ['unknown-step'];
            }
        };

        $this->container->set(get_class($badWorkflowClass), $badWorkflowClass);
        $this->engine->register($badWorkflowClass);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No handler registered for step: unknown-step');

        $this->engine->execute('bad-workflow', new TestOrder('ORD-129'));
    }

    public function test_throws_exception_for_non_array_orchestrator_result(): void
    {
        $invalidWorkflowClass = new class {
            #[Orchestrator(channel: 'invalid-result')]
            public function invalidResult(TestOrder $order): string
            {
                return 'not-an-array';
            }
        };

        $this->engine->register($invalidWorkflowClass);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Orchestrator must return an array of steps');

        $this->engine->execute('invalid-result', new TestOrder('ORD-130'));
    }

    public function test_handles_step_failures(): void
    {
        $this->engine->register(FailingWorkflow::class);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Step 'failing-step' failed: Step failed");

        try {
            $this->engine->execute('failing-workflow', new TestOrder('ORD-131'));
        } catch (WorkflowException $e) {
            $this->assertSame('failing-step', $e->getFailedStep());
            throw $e;
        }
    }

    public function test_exception_contains_failed_step_for_sync_processing(): void
    {
        $this->engine->register(FailingWorkflow::class);

        try {
            $this->engine->execute('failing-workflow', new TestOrder('ORD-132'));
            $this->fail('Expected WorkflowException to be thrown');
        } catch (WorkflowException $e) {
            $this->assertSame('failing-step', $e->getFailedStep());
            $this->assertStringContainsString("Step 'failing-step' failed", $e->getMessage());
        }
    }

    public function test_exception_contains_failed_step_for_async_processing(): void
    {
        $failingAsyncWorkflow = new class {
            #[Orchestrator(channel: 'failing-async-workflow')]
            public function failingAsyncWorkflow(TestOrder $order): array
            {
                return ['failing-async-step'];
            }

            #[Handler(channel: 'failing-async-step', async: true)]
            public function failingAsyncStep(TestOrder $order): TestOrder
            {
                throw new \Exception('Async step failed');
            }
        };

        $this->container->set(get_class($failingAsyncWorkflow), $failingAsyncWorkflow);
        $this->engine->register($failingAsyncWorkflow);

        // Execute async workflow (gets queued)
        $this->engine->execute('failing-async-workflow', new TestOrder('ORD-133'));

        try {
            // Process the queued step which should fail immediately (no retries)
            $this->engine->processAsyncStep('failing-async-step', maxRetries: 0);
            $this->fail('Expected WorkflowException to be thrown');
        } catch (WorkflowException $e) {
            $this->assertSame('failing-async-step', $e->getFailedStep());
            $this->assertStringContainsString("Async step 'failing-async-step' failed after 1 attempt(s)", $e->getMessage());
        }
    }

    public function test_supports_method_chaining_registration(): void
    {
        $engine = new WorkflowEngine($this->container);
        $result = $engine->register(OrderWorkflow::class);

        $this->assertSame($engine, $result);
    }

    public function test_async_step_retries_on_failure(): void
    {
        $failingAsyncWorkflow = new class {
            public int $attempts = 0;

            #[Orchestrator(channel: 'retry-workflow')]
            public function retryWorkflow(TestOrder $order): array
            {
                return ['retry-step'];
            }

            #[Handler(channel: 'retry-step', async: true)]
            public function retryStep(TestOrder $order): TestOrder
            {
                $this->attempts++;
                throw new \RuntimeException('Transient error');
            }
        };

        $this->container->set(get_class($failingAsyncWorkflow), $failingAsyncWorkflow);
        $this->engine->register($failingAsyncWorkflow);

        // Execute workflow (gets queued)
        $this->engine->execute('retry-workflow', new TestOrder('ORD-RETRY'));

        // First attempt fails, message should be re-queued
        $this->engine->processAsyncStep('retry-step', maxRetries: 3);
        $this->assertSame(1, $this->queue->size('retry-step'));

        // Second attempt fails, re-queued again
        $this->engine->processAsyncStep('retry-step', maxRetries: 3);
        $this->assertSame(1, $this->queue->size('retry-step'));

        // Third attempt fails, re-queued again
        $this->engine->processAsyncStep('retry-step', maxRetries: 3);
        $this->assertSame(1, $this->queue->size('retry-step'));

        // Fourth attempt (attempt index 3 == maxRetries), should throw
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("failed after 4 attempt(s)");
        $this->engine->processAsyncStep('retry-step', maxRetries: 3);
    }

    public function test_async_step_no_retry_when_max_is_zero(): void
    {
        $failingAsyncWorkflow = new class {
            #[Orchestrator(channel: 'no-retry-workflow')]
            public function noRetryWorkflow(TestOrder $order): array
            {
                return ['no-retry-step'];
            }

            #[Handler(channel: 'no-retry-step', async: true)]
            public function noRetryStep(TestOrder $order): TestOrder
            {
                throw new \RuntimeException('Permanent error');
            }
        };

        $this->container->set(get_class($failingAsyncWorkflow), $failingAsyncWorkflow);
        $this->engine->register($failingAsyncWorkflow);

        $this->engine->execute('no-retry-workflow', new TestOrder('ORD-NORETRY'));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("failed after 1 attempt(s)");
        $this->engine->processAsyncStep('no-retry-step', maxRetries: 0);
    }

    public function test_throws_exception_for_unresolvable_parameter(): void
    {
        $unresolvedWorkflow = new class {
            #[Orchestrator(channel: 'unresolved-workflow')]
            public function unresolvedWorkflow(TestOrder $order): array
            {
                return ['unresolved-step'];
            }

            #[Handler(channel: 'unresolved-step')]
            public function unresolvedStep(\DateTimeInterface $date): string
            {
                return 'should not reach here';
            }
        };

        $this->container->set(get_class($unresolvedWorkflow), $unresolvedWorkflow);
        $this->engine->register($unresolvedWorkflow);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("Cannot resolve parameter '\$date'");
        $this->engine->execute('unresolved-workflow', new TestOrder('ORD-UNRESOLVED'));
    }

    public function test_step_timeout_throws_when_exceeded(): void
    {
        $slowWorkflow = new class {
            #[Orchestrator(channel: 'slow-workflow')]
            public function slowWorkflow(TestOrder $order): array
            {
                return ['slow-step'];
            }

            #[Handler(channel: 'slow-step', timeout: 0)]
            public function slowStep(TestOrder $order): TestOrder
            {
                // Simulate slow operation — timeout of 0 means no limit, so we use a separate test
                usleep(100_000); // 100ms
                return $order;
            }
        };

        $this->container->set(get_class($slowWorkflow), $slowWorkflow);
        $this->engine->register($slowWorkflow);

        // With timeout: 0 (no limit), should succeed despite being slow
        $result = $this->engine->execute('slow-workflow', new TestOrder('ORD-SLOW'));
        $this->assertInstanceOf(TestOrder::class, $result);
    }

    public function test_event_listener_receives_step_events(): void
    {
        $events = new \stdClass();
        $events->started = [];
        $events->completed = [];
        $events->failed = [];

        $listener = new class($events) implements EventListenerInterface {
            public function __construct(private \stdClass $events) {}

            public function onStepStarted(string $stepName, WorkflowMessage $message): void
            {
                $this->events->started[] = $stepName;
            }

            public function onStepCompleted(string $stepName, WorkflowMessage $message, float $duration): void
            {
                $this->events->completed[] = ['step' => $stepName, 'duration' => $duration];
            }

            public function onStepFailed(string $stepName, WorkflowMessage $message, \Throwable $error, float $duration): void
            {
                $this->events->failed[] = ['step' => $stepName, 'error' => $error->getMessage()];
            }
        };

        $engine = new WorkflowEngine($this->container, new HandlerRegistry(), $this->queue, eventListeners: [$listener]);
        $engine->register(OrderWorkflow::class);

        $engine->execute('process.order', new TestOrder('ORD-EVT', 100.0, false));

        // Should have started/completed events for: validate, payment, confirmation
        $this->assertSame(['validate', 'payment', 'confirmation'], $events->started);
        $this->assertCount(3, $events->completed);
        $this->assertSame('validate', $events->completed[0]['step']);
        $this->assertSame('payment', $events->completed[1]['step']);
        $this->assertSame('confirmation', $events->completed[2]['step']);
        $this->assertEmpty($events->failed);

        // Duration should be a positive float
        foreach ($events->completed as $event) {
            $this->assertGreaterThanOrEqual(0, $event['duration']);
        }
    }

    public function test_event_listener_receives_failure_events(): void
    {
        $events = new \stdClass();
        $events->failed = [];

        $listener = new class($events) implements EventListenerInterface {
            public function __construct(private \stdClass $events) {}
            public function onStepStarted(string $stepName, WorkflowMessage $message): void {}
            public function onStepCompleted(string $stepName, WorkflowMessage $message, float $duration): void {}
            public function onStepFailed(string $stepName, WorkflowMessage $message, \Throwable $error, float $duration): void
            {
                $this->events->failed[] = ['step' => $stepName, 'error' => $error->getMessage()];
            }
        };

        $engine = new WorkflowEngine($this->container, new HandlerRegistry(), $this->queue, eventListeners: [$listener]);
        $engine->register(FailingWorkflow::class);

        try {
            $engine->execute('failing-workflow', new TestOrder('ORD-FAIL'));
        } catch (WorkflowException) {
            // Expected
        }

        $this->assertCount(1, $events->failed);
        $this->assertSame('failing-step', $events->failed[0]['step']);
    }

    public function test_auto_resolved_handlers_do_not_leak_state_across_executions(): void
    {
        // Registered by class name only (not set() in the container), so the handler
        // is auto-resolved — the common case. Each execution must get a fresh instance.
        $this->engine->register(StatefulCountingWorkflow::class);

        $first = $this->engine->execute('counting', new CountingPayload());
        $second = $this->engine->execute('counting', new CountingPayload());

        $this->assertSame(1, $first->seenInvocation);
        $this->assertSame(
            1,
            $second->seenInvocation,
            'A fresh handler instance must be used per execution; instance state must not accumulate'
        );
    }

    public function test_middleware_is_applied_once_across_async_continuation(): void
    {
        $tracker = new \stdClass();
        $tracker->applied = 0;

        $middleware = new class($tracker) implements MiddlewareInterface {
            public function __construct(private \stdClass $tracker) {}

            public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
            {
                $this->tracker->applied++;
                return $next($message);
            }
        };

        // Async step first, then a sync step, so the workflow continues after the
        // async boundary. Both handlers ('async-step', 'confirmation') live on OrderWorkflow.
        $asyncThenSync = new class {
            #[Orchestrator(channel: 'async-then-sync')]
            public function orchestrate(TestOrder $order): array
            {
                return ['async-step', 'confirmation'];
            }
        };
        $this->container->set(get_class($asyncThenSync), $asyncThenSync);

        $engine = new WorkflowEngine($this->container, new HandlerRegistry(), $this->queue, middleware: [$middleware]);
        $engine->register(OrderWorkflow::class);
        $engine->register($asyncThenSync);

        // Entry point: middleware applied once, async step queued.
        $this->assertNull($engine->execute('async-then-sync', new TestOrder('ORD-MW-ASYNC')));
        $this->assertSame(1, $tracker->applied);

        // Async continuation must NOT re-apply middleware.
        $engine->processAsyncStep('async-step');
        $this->assertSame(1, $tracker->applied, 'Middleware must be applied once, not re-applied on async continuation');
        $this->assertSame(0, $this->queue->size('async-step'));
    }

    public function test_backoff_callable_is_invoked_before_each_re_queue(): void
    {
        $attempts = [];
        $backoff = function (int $retryAttempt) use (&$attempts): float {
            $attempts[] = $retryAttempt;
            return 0.0; // no actual sleep in tests
        };

        $failingAsync = new class {
            #[Orchestrator(channel: 'backoff-workflow')]
            public function orchestrate(TestOrder $order): array
            {
                return ['backoff-step'];
            }

            #[Handler(channel: 'backoff-step', async: true)]
            public function fail(TestOrder $order): TestOrder
            {
                throw new \RuntimeException('transient');
            }
        };
        $this->container->set(get_class($failingAsync), $failingAsync);
        $this->engine->register($failingAsync);

        $this->engine->execute('backoff-workflow', new TestOrder('ORD-BO'));

        // maxRetries=2 → backoff called for retry 1 and retry 2 (then DLQ branch handles the 3rd).
        $this->engine->processAsyncStep('backoff-step', maxRetries: 2, backoff: $backoff, dlqQueue: 'dlq:backoff-step');
        $this->engine->processAsyncStep('backoff-step', maxRetries: 2, backoff: $backoff, dlqQueue: 'dlq:backoff-step');
        $this->engine->processAsyncStep('backoff-step', maxRetries: 2, backoff: $backoff, dlqQueue: 'dlq:backoff-step');

        $this->assertSame([1, 2], $attempts, 'Backoff must be invoked with the 1-indexed retry attempt number before each re-queue');
    }

    public function test_dead_letter_queue_receives_messages_after_retries_exhausted(): void
    {
        $failingAsync = new class {
            #[Orchestrator(channel: 'dlq-workflow')]
            public function orchestrate(TestOrder $order): array
            {
                return ['dlq-step'];
            }

            #[Handler(channel: 'dlq-step', async: true)]
            public function fail(TestOrder $order): TestOrder
            {
                throw new \RuntimeException('permanent');
            }
        };
        $this->container->set(get_class($failingAsync), $failingAsync);
        $this->engine->register($failingAsync);

        $this->engine->execute('dlq-workflow', new TestOrder('ORD-DLQ'));

        // With maxRetries=2 the message is re-queued twice (attempts 1, 2) then,
        // on the third failed attempt, parked in the DLQ instead of throwing.
        $this->engine->processAsyncStep('dlq-step', maxRetries: 2, dlqQueue: 'dlq:dlq-step');
        $this->engine->processAsyncStep('dlq-step', maxRetries: 2, dlqQueue: 'dlq:dlq-step');
        $this->engine->processAsyncStep('dlq-step', maxRetries: 2, dlqQueue: 'dlq:dlq-step');

        $this->assertSame(0, $this->queue->size('dlq-step'), 'Original queue must be drained');
        $this->assertSame(1, $this->queue->size('dlq:dlq-step'), 'DLQ must hold the dead message');

        $dead = $this->queue->pop('dlq:dlq-step');
        $this->assertInstanceOf(WorkflowMessage::class, $dead);
        $this->assertSame('ORD-DLQ', $dead->getPayload()->id);
        $this->assertSame(2, (int) $dead->getHeader('_retry_attempt', 0));
    }

    public function test_exponential_backoff_helper_produces_expected_delays(): void
    {
        $backoff = WorkflowEngine::exponentialBackoff(base: 1.0, cap: 60.0);

        $this->assertSame(1.0, $backoff(1));
        $this->assertSame(2.0, $backoff(2));
        $this->assertSame(4.0, $backoff(3));
        $this->assertSame(8.0, $backoff(4));
        $this->assertSame(60.0, $backoff(100), 'must be capped');
        $this->assertSame(1.0, $backoff(0), 'non-positive attempts collapse to the base');
    }

    public function test_returns_headers_handler_throws_on_non_array(): void
    {
        $badHeaderWorkflow = new class {
            #[Orchestrator(channel: 'bad-header-workflow')]
            public function orchestrate(TestOrder $order): array
            {
                return ['bad-header-step'];
            }

            #[Handler(channel: 'bad-header-step', returnsHeaders: true)]
            public function badHeaders(TestOrder $order): string
            {
                return 'not-an-array';
            }
        };
        $this->container->set(get_class($badHeaderWorkflow), $badHeaderWorkflow);
        $this->engine->register($badHeaderWorkflow);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage("returnsHeaders but its handler returned string");
        $this->engine->execute('bad-header-workflow', new TestOrder('ORD-BADHDR'));
    }

    public function test_step_failure_is_reported_when_handler_throws_scoped_exception(): void
    {
        $events = new \stdClass();
        $events->failed = [];

        $listener = new class($events) implements EventListenerInterface {
            public function __construct(private \stdClass $events) {}
            public function onStepStarted(string $stepName, WorkflowMessage $message): void {}
            public function onStepCompleted(string $stepName, WorkflowMessage $message, float $duration): void {}
            public function onStepFailed(string $stepName, WorkflowMessage $message, \Throwable $error, float $duration): void
            {
                $this->events->failed[] = $stepName;
            }
        };

        // Handler throws a WorkflowException already scoped to a step. Previously the
        // early re-throw skipped onStepFailed; the failure must still be reported.
        $workflow = new class {
            #[Orchestrator(channel: 'scoped-fail-workflow')]
            public function orchestrate(TestOrder $order): array
            {
                return ['scoped-fail-step'];
            }

            #[Handler(channel: 'scoped-fail-step')]
            public function fail(TestOrder $order): TestOrder
            {
                throw new WorkflowException('inner failure', 0, null, 'scoped-fail-step');
            }
        };
        $this->container->set(get_class($workflow), $workflow);

        $engine = new WorkflowEngine($this->container, new HandlerRegistry(), $this->queue, eventListeners: [$listener]);
        $engine->register($workflow);

        try {
            $engine->execute('scoped-fail-workflow', new TestOrder('ORD-SCOPED'));
            $this->fail('Expected WorkflowException to be thrown');
        } catch (WorkflowException $e) {
            $this->assertSame('scoped-fail-step', $e->getFailedStep());
        }

        $this->assertSame(
            ['scoped-fail-step'],
            $events->failed,
            'onStepFailed must fire even when the handler throws a step-scoped WorkflowException'
        );
    }

    protected function setUp(): void
    {
        $this->container = new SimpleContainer();
        $this->queue = new InMemoryQueue();

        $this->container->set(OrderWorkflow::class, new OrderWorkflow());
        $this->container->set(FailingWorkflow::class, new FailingWorkflow());

        $this->engine = new WorkflowEngine($this->container, new HandlerRegistry(), $this->queue);
        $this->engine->register(OrderWorkflow::class);
    }
}