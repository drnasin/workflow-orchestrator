<?php

namespace WorkflowOrchestrator\Tests\Exceptions;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Exceptions\WorkflowException;

class WorkflowExceptionTest extends TestCase
{
    public function test_can_create_exception_without_failed_step(): void
    {
        $exception = new WorkflowException('Test message');

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertNull($exception->getFailedStep());
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function test_can_create_exception_with_failed_step(): void
    {
        $exception = new WorkflowException('Test message', 0, null, 'test-step');

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame('test-step', $exception->getFailedStep());
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
    }

    public function test_can_create_exception_with_all_parameters(): void
    {
        $previousException = new \Exception('Previous error');
        $exception = new WorkflowException('Test message', 500, $previousException, 'payment-step');

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame('payment-step', $exception->getFailedStep());
        $this->assertSame(500, $exception->getCode());
        $this->assertSame($previousException, $exception->getPrevious());
    }

    public function test_failed_step_defaults_to_null(): void
    {
        $exception = new WorkflowException('Test message', 123);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertNull($exception->getFailedStep());
        $this->assertSame(123, $exception->getCode());
    }

    public function test_extends_base_exception(): void
    {
        $exception = new WorkflowException('Test message');

        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function test_failed_step_can_be_empty_string(): void
    {
        $exception = new WorkflowException('Test message', 0, null, '');

        $this->assertSame('', $exception->getFailedStep());
    }
}