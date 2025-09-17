<?php

namespace WorkflowOrchestrator\Tests\Container;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Container\SimpleContainer;
use WorkflowOrchestrator\Exceptions\ContainerException;

class TestService
{
    public function __construct(public string $value = 'default')
    {
    }
}

class SimpleContainerTest extends TestCase
{
    private SimpleContainer $container;

    public function test_stores_and_retrieves_object_instance(): void
    {
        $service = new TestService('test');
        $this->container->set('test.service', $service);

        $this->assertTrue($this->container->has('test.service'));
        $this->assertSame($service, $this->container->get('test.service'));
    }

    public function test_stores_and_executes_factory(): void
    {
        $this->container->set('test.service', function () {
            return new TestService('factory');
        });

        $this->assertTrue($this->container->has('test.service'));

        $service1 = $this->container->get('test.service');
        $service2 = $this->container->get('test.service');

        $this->assertInstanceOf(TestService::class, $service1);
        $this->assertSame('factory', $service1->value);
        $this->assertSame($service1, $service2); // Should be singleton
    }

    public function test_auto_resolves_existing_class(): void
    {
        $this->assertTrue($this->container->has(TestService::class));

        $service = $this->container->get(TestService::class);

        $this->assertInstanceOf(TestService::class, $service);
        $this->assertSame('default', $service->value);
    }

    public function test_throws_exception_for_nonexistent_service(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Service not found: NonExistentClass');

        $this->container->get('NonExistentClass');
    }

    public function test_returns_false_for_nonexistent_class(): void
    {
        $this->assertFalse($this->container->has('NonExistentClass'));
    }

    public function test_factory_is_called_only_once(): void
    {
        $callCount = 0;
        $this->container->set('counter', function () use (&$callCount) {
            $callCount++;
            return new TestService("call-$callCount");
        });

        $service1 = $this->container->get('counter');
        $service2 = $this->container->get('counter');

        $this->assertSame(1, $callCount);
        $this->assertSame($service1, $service2);
        $this->assertSame('call-1', $service1->value);
    }

    protected function setUp(): void
    {
        $this->container = new SimpleContainer();
    }
}
