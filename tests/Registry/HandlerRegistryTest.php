<?php

namespace WorkflowOrchestrator\Tests\Registry;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Attributes\Handler;
use WorkflowOrchestrator\Attributes\Orchestrator;
use WorkflowOrchestrator\Exceptions\WorkflowException;
use WorkflowOrchestrator\Registry\HandlerRegistry;

class TestWorkflowClass
{
    #[Orchestrator(channel: 'test.orchestrator')]
    public function orchestrate($payload): array
    {
        return ['step1', 'step2'];
    }

    #[Handler(channel: 'test.handler')]
    public function handle($payload): mixed
    {
        return $payload;
    }

    #[Handler(channel: 'async.handler', async: true)]
    public function handleAsync($payload): mixed
    {
        return $payload;
    }

    #[Handler(channel: 'header.handler', returnsHeaders: true)]
    public function handleWithHeaders($payload): array
    {
        return ['key' => 'value'];
    }

    public function regularMethod(): void
    {
        // This should not be registered
    }
}

class HandlerRegistryTest extends TestCase
{
    private HandlerRegistry $registry;

    public function test_registers_orchestrator_from_class(): void
    {
        $this->registry->registerClass(TestWorkflowClass::class);

        $this->assertTrue($this->registry->hasOrchestrator('test.orchestrator'));

        $orchestrator = $this->registry->getOrchestrator('test.orchestrator');
        $this->assertSame(TestWorkflowClass::class, $orchestrator['class']);
        $this->assertSame('orchestrate', $orchestrator['method']);
    }

    public function test_registers_handlers_from_class(): void
    {
        $this->registry->registerClass(TestWorkflowClass::class);

        $this->assertTrue($this->registry->hasHandler('test.handler'));
        $this->assertTrue($this->registry->hasHandler('async.handler'));
        $this->assertTrue($this->registry->hasHandler('header.handler'));

        $handler = $this->registry->getHandler('test.handler');
        $this->assertSame(TestWorkflowClass::class, $handler['class']);
        $this->assertSame('handle', $handler['method']);
        $this->assertFalse($handler['async']);
        $this->assertFalse($handler['returnsHeaders']);

        $asyncHandler = $this->registry->getHandler('async.handler');
        $this->assertTrue($asyncHandler['async']);

        $headerHandler = $this->registry->getHandler('header.handler');
        $this->assertTrue($headerHandler['returnsHeaders']);
    }

    public function test_registers_from_object_instance(): void
    {
        $instance = new TestWorkflowClass();
        $this->registry->registerClass($instance);

        $this->assertTrue($this->registry->hasOrchestrator('test.orchestrator'));
        $this->assertTrue($this->registry->hasHandler('test.handler'));
    }

    public function test_throws_exception_for_unknown_orchestrator(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No orchestrator found for channel: unknown');

        $this->registry->getOrchestrator('unknown');
    }

    public function test_throws_exception_for_unknown_handler(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('No handler found for channel: unknown');

        $this->registry->getHandler('unknown');
    }

    public function test_checks_existence_correctly(): void
    {
        $this->assertFalse($this->registry->hasOrchestrator('nonexistent'));
        $this->assertFalse($this->registry->hasHandler('nonexistent'));

        $this->registry->registerClass(TestWorkflowClass::class);

        $this->assertTrue($this->registry->hasOrchestrator('test.orchestrator'));
        $this->assertTrue($this->registry->hasHandler('test.handler'));
        $this->assertFalse($this->registry->hasOrchestrator('nonexistent'));
        $this->assertFalse($this->registry->hasHandler('nonexistent'));
    }

    public function test_rejects_empty_handler_channel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler channel name cannot be empty');
        new Handler(channel: '');
    }

    public function test_rejects_whitespace_handler_channel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler channel name cannot be empty');
        new Handler(channel: '   ');
    }

    public function test_rejects_empty_orchestrator_channel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Orchestrator channel name cannot be empty');
        new Orchestrator(channel: '');
    }

    public function test_stores_handler_timeout(): void
    {
        $this->registry->registerClass(TestWorkflowClass::class);

        $handler = $this->registry->getHandler('test.handler');
        $this->assertSame(0, $handler['timeout']);
    }

    protected function setUp(): void
    {
        $this->registry = new HandlerRegistry();
    }
}