<?php

namespace WorkflowOrchestrator\Tests\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Attributes\Handler;
use WorkflowOrchestrator\Attributes\Header;
use WorkflowOrchestrator\Attributes\Orchestrator;
use WorkflowOrchestrator\Container\SimpleContainer;
use WorkflowOrchestrator\Engine\WorkflowEngine;
use WorkflowOrchestrator\Exceptions\WorkflowException;
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

        $this->engine->execute('failing-workflow', new TestOrder('ORD-131'));
    }

    public function test_supports_method_chaining_registration(): void
    {
        $engine = new WorkflowEngine($this->container);
        $result = $engine->register(OrderWorkflow::class);

        $this->assertSame($engine, $result);
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