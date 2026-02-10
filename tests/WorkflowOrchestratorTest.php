<?php
namespace WorkflowOrchestrator\Tests;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Attributes\Handler;
use WorkflowOrchestrator\Attributes\Orchestrator;
use WorkflowOrchestrator\Contracts\EventListenerInterface;
use WorkflowOrchestrator\Contracts\MiddlewareInterface;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\WorkflowOrchestrator;

class SimpleOrder
{
    public function __construct(
        public string $id,
        public bool $validated = false,
        public bool $paid = false
    ) {}
}

class SimpleWorkflow
{
    #[Orchestrator(channel: 'simple.process')]
    public function process(SimpleOrder $order): array
    {
        return ['validate', 'payment'];
    }

    #[Handler(channel: 'validate')]
    public function validate(SimpleOrder $order): SimpleOrder
    {
        $order->validated = true;
        return $order;
    }

    #[Handler(channel: 'payment')]
    public function payment(SimpleOrder $order): SimpleOrder
    {
        $order->paid = true;
        return $order;
    }
}

class WorkflowOrchestratorTest extends TestCase
{
    public function test_static_factory_creates_instance(): void
    {
        $orchestrator = WorkflowOrchestrator::create();

        $this->assertInstanceOf(WorkflowOrchestrator::class, $orchestrator);
    }

    public function test_registers_and_executes_workflow(): void
    {
        $orchestrator = WorkflowOrchestrator::create()
            ->register(SimpleWorkflow::class);

        $order = new SimpleOrder('ORD-001');
        $result = $orchestrator->execute('simple.process', $order);

        $this->assertInstanceOf(SimpleOrder::class, $result);
        $this->assertTrue($result->validated);
        $this->assertTrue($result->paid);
    }

    public function test_supports_method_chaining(): void
    {
        $result = WorkflowOrchestrator::create()
            ->register(SimpleWorkflow::class)
            ->register(SimpleWorkflow::class); // Can register multiple

        $this->assertInstanceOf(WorkflowOrchestrator::class, $result);
    }

    public function test_executes_with_headers(): void
    {
        $orchestrator = WorkflowOrchestrator::create()
            ->register(SimpleWorkflow::class);

        $order = new SimpleOrder('ORD-002');
        $headers = ['priority' => 'high', 'source' => 'api'];

        $result = $orchestrator->execute('simple.process', $order, $headers);

        $this->assertInstanceOf(SimpleOrder::class, $result);
        $this->assertTrue($result->validated);
        $this->assertTrue($result->paid);
    }

    public function test_provides_access_to_engine(): void
    {
        $orchestrator = WorkflowOrchestrator::create();

        $this->assertNotNull($orchestrator->getEngine());
    }

    public function test_processes_async_steps(): void
    {
        $orchestrator = WorkflowOrchestrator::create()
            ->register(SimpleWorkflow::class);

        // This should not throw an exception
        $orchestrator->processAsyncStep('nonexistent-queue');

        $this->assertTrue(true);
    }

    public function test_with_middleware_adds_middleware(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
            {
                return $next($message->withHeader('middleware_applied', true));
            }
        };

        $orchestrator = WorkflowOrchestrator::create()
            ->withMiddleware($middleware)
            ->register(SimpleWorkflow::class);

        $order = new SimpleOrder('ORD-MW');
        $result = $orchestrator->execute('simple.process', $order);

        $this->assertInstanceOf(SimpleOrder::class, $result);
        $this->assertTrue($result->validated);
        $this->assertTrue($result->paid);
    }

    public function test_with_middleware_is_immutable(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
            {
                return $next($message);
            }
        };

        $original = WorkflowOrchestrator::create();
        $withMiddleware = $original->withMiddleware($middleware);

        $this->assertNotSame($original, $withMiddleware);
    }

    public function test_with_queue_preserves_middleware(): void
    {
        $tracker = new \stdClass();
        $tracker->applied = false;

        $middleware = new class($tracker) implements MiddlewareInterface {
            public function __construct(private \stdClass $tracker) {}

            public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
            {
                $this->tracker->applied = true;
                return $next($message);
            }
        };

        $queue = new \WorkflowOrchestrator\Queue\InMemoryQueue();

        $orchestrator = WorkflowOrchestrator::create()
            ->withMiddleware($middleware)
            ->withQueue($queue)
            ->register(SimpleWorkflow::class);

        $orchestrator->execute('simple.process', new SimpleOrder('ORD-QMW'));

        $this->assertTrue($tracker->applied);
    }

    public function test_multiple_middleware_execute_in_order(): void
    {
        $tracker = new \stdClass();
        $tracker->order = [];

        $first = new class($tracker) implements MiddlewareInterface {
            public function __construct(private \stdClass $tracker) {}
            public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
            {
                $this->tracker->order[] = 'first';
                return $next($message);
            }
        };

        $second = new class($tracker) implements MiddlewareInterface {
            public function __construct(private \stdClass $tracker) {}
            public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
            {
                $this->tracker->order[] = 'second';
                return $next($message);
            }
        };

        $third = new class($tracker) implements MiddlewareInterface {
            public function __construct(private \stdClass $tracker) {}
            public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
            {
                $this->tracker->order[] = 'third';
                return $next($message);
            }
        };

        $orchestrator = WorkflowOrchestrator::create()
            ->withMiddleware($first)
            ->withMiddleware($second)
            ->withMiddleware($third)
            ->register(SimpleWorkflow::class);

        $orchestrator->execute('simple.process', new SimpleOrder('ORD-ORD'));

        $this->assertSame(['first', 'second', 'third'], $tracker->order);
    }

    public function test_with_event_listener_is_immutable(): void
    {
        $listener = new class implements EventListenerInterface {
            public function onStepStarted(string $stepName, WorkflowMessage $message): void {}
            public function onStepCompleted(string $stepName, WorkflowMessage $message, float $duration): void {}
            public function onStepFailed(string $stepName, WorkflowMessage $message, \Throwable $error, float $duration): void {}
        };

        $original = WorkflowOrchestrator::create();
        $withListener = $original->withEventListener($listener);

        $this->assertNotSame($original, $withListener);
    }

    public function test_with_event_listener_fires_events(): void
    {
        $tracker = new \stdClass();
        $tracker->steps = [];

        $listener = new class($tracker) implements EventListenerInterface {
            public function __construct(private \stdClass $tracker) {}
            public function onStepStarted(string $stepName, WorkflowMessage $message): void
            {
                $this->tracker->steps[] = $stepName;
            }
            public function onStepCompleted(string $stepName, WorkflowMessage $message, float $duration): void {}
            public function onStepFailed(string $stepName, WorkflowMessage $message, \Throwable $error, float $duration): void {}
        };

        $orchestrator = WorkflowOrchestrator::create()
            ->withEventListener($listener)
            ->register(SimpleWorkflow::class);

        $orchestrator->execute('simple.process', new SimpleOrder('ORD-EVT'));

        $this->assertSame(['validate', 'payment'], $tracker->steps);
    }
}