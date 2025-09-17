<?php
namespace WorkflowOrchestrator\Tests\Queue;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\Queue\InMemoryQueue;

class InMemoryQueueTest extends TestCase
{
    private InMemoryQueue $queue;

    protected function setUp(): void
    {
        $this->queue = new InMemoryQueue();
    }

    public function test_pushes_and_pops_messages(): void
    {
        $message = new WorkflowMessage('test-payload');

        $this->queue->push('test-queue', $message);

        $this->assertSame(1, $this->queue->size('test-queue'));

        $retrieved = $this->queue->pop('test-queue');

        $this->assertSame($message, $retrieved);
        $this->assertSame(0, $this->queue->size('test-queue'));
    }

    public function test_returns_null_for_empty_queue(): void
    {
        $result = $this->queue->pop('empty-queue');

        $this->assertNull($result);
    }

    public function test_maintains_separate_queues(): void
    {
        $message1 = new WorkflowMessage('payload1');
        $message2 = new WorkflowMessage('payload2');

        $this->queue->push('queue1', $message1);
        $this->queue->push('queue2', $message2);

        $this->assertSame(1, $this->queue->size('queue1'));
        $this->assertSame(1, $this->queue->size('queue2'));

        $retrieved1 = $this->queue->pop('queue1');
        $retrieved2 = $this->queue->pop('queue2');

        $this->assertSame($message1, $retrieved1);
        $this->assertSame($message2, $retrieved2);
    }

    public function test_maintains_fifo_order(): void
    {
        $message1 = new WorkflowMessage('first');
        $message2 = new WorkflowMessage('second');
        $message3 = new WorkflowMessage('third');

        $this->queue->push('test', $message1);
        $this->queue->push('test', $message2);
        $this->queue->push('test', $message3);

        $this->assertSame($message1, $this->queue->pop('test'));
        $this->assertSame($message2, $this->queue->pop('test'));
        $this->assertSame($message3, $this->queue->pop('test'));
    }

    public function test_clears_queue(): void
    {
        $this->queue->push('test', new WorkflowMessage('payload1'));
        $this->queue->push('test', new WorkflowMessage('payload2'));

        $this->assertSame(2, $this->queue->size('test'));

        $this->queue->clear('test');

        $this->assertSame(0, $this->queue->size('test'));
        $this->assertNull($this->queue->pop('test'));
    }

    public function test_size_returns_zero_for_nonexistent_queue(): void
    {
        $this->assertSame(0, $this->queue->size('nonexistent'));
    }
}