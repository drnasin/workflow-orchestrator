<?php

namespace WorkflowOrchestrator\Tests\Queue;

use PDO;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\Queue\SqliteQueue;

class DatabaseQueueTest extends AbstractQueueTest
{
    private PDO $pdo;

    public function test_can_use_custom_table_name(): void
    {
        $customQueue = new SqliteQueue($this->pdo, 'custom_queue_table');

        $customQueue->push('test', new WorkflowMessage('payload', [], []));

        $this->assertSame(1, $customQueue->size('test'));

        $message = $customQueue->pop('test');
        $this->assertSame('payload', $message->getPayload());
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
