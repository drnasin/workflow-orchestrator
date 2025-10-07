<?php

namespace WorkflowOrchestrator\Tests\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\Queue\SqliteQueue;

class DatabaseQueueTest extends TestCase
{
    private PDO $pdo;
    private SqliteQueue $queue;

    public function test_can_push_and_pop_message(): void
    {
        $message = new WorkflowMessage('test-payload', ['step1', 'step2'], ['header1' => 'value1']);

        $this->queue->push('test-queue', $message);

        $retrievedMessage = $this->queue->pop('test-queue');

        $this->assertInstanceOf(WorkflowMessage::class, $retrievedMessage);
        $this->assertSame('test-payload', $retrievedMessage->getPayload());
        $this->assertSame(['step1', 'step2'], $retrievedMessage->getSteps());
        $this->assertSame(['header1' => 'value1'], $retrievedMessage->getAllHeaders());
    }

    public function test_pop_returns_null_for_empty_queue(): void
    {
        $result = $this->queue->pop('empty-queue');

        $this->assertNull($result);
    }

    public function test_pop_returns_messages_in_fifo_order(): void
    {
        $message1 = new WorkflowMessage('payload1', ['step1'], []);
        $message2 = new WorkflowMessage('payload2', ['step2'], []);
        $message3 = new WorkflowMessage('payload3', ['step3'], []);

        $this->queue->push('test-queue', $message1);
        $this->queue->push('test-queue', $message2);
        $this->queue->push('test-queue', $message3);

        $first = $this->queue->pop('test-queue');
        $second = $this->queue->pop('test-queue');
        $third = $this->queue->pop('test-queue');

        $this->assertSame('payload1', $first->getPayload());
        $this->assertSame('payload2', $second->getPayload());
        $this->assertSame('payload3', $third->getPayload());
    }

    public function test_size_returns_correct_count(): void
    {
        $this->assertSame(0, $this->queue->size('test-queue'));

        $this->queue->push('test-queue', new WorkflowMessage('payload1', [], []));
        $this->assertSame(1, $this->queue->size('test-queue'));

        $this->queue->push('test-queue', new WorkflowMessage('payload2', [], []));
        $this->assertSame(2, $this->queue->size('test-queue'));

        $this->queue->pop('test-queue');
        $this->assertSame(1, $this->queue->size('test-queue'));
    }

    public function test_clear_removes_all_messages_from_queue(): void
    {
        $this->queue->push('test-queue', new WorkflowMessage('payload1', [], []));
        $this->queue->push('test-queue', new WorkflowMessage('payload2', [], []));

        $this->assertSame(2, $this->queue->size('test-queue'));

        $this->queue->clear('test-queue');

        $this->assertSame(0, $this->queue->size('test-queue'));
        $this->assertNull($this->queue->pop('test-queue'));
    }

    public function test_queues_are_isolated(): void
    {
        $this->queue->push('queue1', new WorkflowMessage('payload1', [], []));
        $this->queue->push('queue2', new WorkflowMessage('payload2', [], []));

        $this->assertSame(1, $this->queue->size('queue1'));
        $this->assertSame(1, $this->queue->size('queue2'));

        $message1 = $this->queue->pop('queue1');
        $this->assertSame('payload1', $message1->getPayload());
        $this->assertSame(0, $this->queue->size('queue1'));
        $this->assertSame(1, $this->queue->size('queue2'));

        $message2 = $this->queue->pop('queue2');
        $this->assertSame('payload2', $message2->getPayload());
        $this->assertSame(0, $this->queue->size('queue2'));
    }

    public function test_can_use_custom_table_name(): void
    {
        $customQueue = new SqliteQueue($this->pdo, 'custom_queue_table');

        $customQueue->push('test', new WorkflowMessage('payload', [], []));

        $this->assertSame(1, $customQueue->size('test'));

        $message = $customQueue->pop('test');
        $this->assertSame('payload', $message->getPayload());
    }

    public function test_handles_complex_payloads(): void
    {
        $complexPayload = (object)[
            'id'    => 123,
            'data'  => ['nested' => ['array' => true]],
            'items' => [1, 2, 3, 4, 5]
        ];

        $message = new WorkflowMessage($complexPayload, ['step1'], ['context' => 'test']);

        $this->queue->push('complex-queue', $message);
        $retrieved = $this->queue->pop('complex-queue');

        $this->assertEquals($complexPayload, $retrieved->getPayload());
        $this->assertSame(['step1'], $retrieved->getSteps());
        $this->assertSame(['context' => 'test'], $retrieved->getAllHeaders());
    }

    public function test_handles_empty_queue_gracefully(): void
    {
        // Test multiple pops on empty queue don't cause issues
        $this->assertNull($this->queue->pop('empty-queue'));
        $this->assertNull($this->queue->pop('empty-queue'));
        $this->assertNull($this->queue->pop('empty-queue'));

        $this->assertSame(0, $this->queue->size('empty-queue'));

        // Add a message and ensure it works normally after empty pops
        $this->queue->push('empty-queue', new WorkflowMessage('test', [], []));
        $this->assertSame(1, $this->queue->size('empty-queue'));

        $message = $this->queue->pop('empty-queue');
        $this->assertSame('test', $message->getPayload());
        $this->assertSame(0, $this->queue->size('empty-queue'));
    }

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->queue = new SqliteQueue($this->pdo);
    }
}