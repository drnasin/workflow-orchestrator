<?php
namespace WorkflowOrchestrator\Tests;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Attributes\Handler;
use WorkflowOrchestrator\Attributes\Orchestrator;
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
}