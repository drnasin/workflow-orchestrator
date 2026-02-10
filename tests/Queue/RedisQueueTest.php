<?php

namespace WorkflowOrchestrator\Tests\Queue;

use Redis;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\Queue\RedisQueue;

class RedisQueueTest extends AbstractQueueTest
{
    private Redis $redis;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension is not available');
        }

        try {
            $this->redis = new Redis();
            $this->redis->connect('127.0.0.1', 6379);
            $this->redis->select(15); // Use database 15 for tests
            $this->redis->flushDB();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis server is not available: ' . $e->getMessage());
        }

        $this->queue = new RedisQueue($this->redis, 'test_workflow_queue:');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->redis)) {
                $this->redis->flushDB();
                $this->redis->close();
            }
        } catch (\Throwable) {
            // Server unavailable, nothing to clean up
        }
    }

    public function test_can_use_custom_key_prefix(): void
    {
        $customQueue = new RedisQueue($this->redis, 'custom_prefix:');

        $customQueue->push('test', new WorkflowMessage('payload', [], []));

        $this->assertSame(1, $customQueue->size('test'));

        // Original queue shouldn't see the message
        $this->assertSame(0, $this->queue->size('test'));

        $message = $customQueue->pop('test');
        $this->assertSame('payload', $message->getPayload());
    }

    public function test_peek_returns_first_message_without_removing(): void
    {
        /** @var RedisQueue $queue */
        $queue = $this->queue;

        $message1 = new WorkflowMessage('payload1', [], []);
        $message2 = new WorkflowMessage('payload2', [], []);

        $queue->push('peek-queue', $message1);
        $queue->push('peek-queue', $message2);

        $peeked = $queue->peek('peek-queue');
        $this->assertSame('payload1', $peeked->getPayload());
        $this->assertSame(2, $queue->size('peek-queue'));

        // Peek again should return same message
        $peekedAgain = $queue->peek('peek-queue');
        $this->assertSame('payload1', $peekedAgain->getPayload());
        $this->assertSame(2, $queue->size('peek-queue'));

        // Pop should return the peeked message
        $popped = $queue->pop('peek-queue');
        $this->assertSame('payload1', $popped->getPayload());
        $this->assertSame(1, $queue->size('peek-queue'));
    }

    public function test_peek_returns_null_for_empty_queue(): void
    {
        /** @var RedisQueue $queue */
        $queue = $this->queue;

        $result = $queue->peek('empty-queue');
        $this->assertNull($result);
    }

    public function test_get_queue_names_returns_active_queues(): void
    {
        /** @var RedisQueue $queue */
        $queue = $this->queue;

        $queue->push('queue1', new WorkflowMessage('test1', [], []));
        $queue->push('queue2', new WorkflowMessage('test2', [], []));
        $queue->push('queue3', new WorkflowMessage('test3', [], []));

        $queueNames = $queue->getQueueNames();

        $this->assertCount(3, $queueNames);
        $this->assertContains('queue1', $queueNames);
        $this->assertContains('queue2', $queueNames);
        $this->assertContains('queue3', $queueNames);
    }

    public function test_blocking_pop_returns_immediately_if_message_available(): void
    {
        /** @var RedisQueue $queue */
        $queue = $this->queue;

        $message = new WorkflowMessage('blocking-test', [], []);
        $queue->push('blocking-queue', $message);

        $start = microtime(true);
        $result = $queue->blockingPop('blocking-queue', 1);
        $elapsed = microtime(true) - $start;

        $this->assertNotNull($result);
        $this->assertSame('blocking-test', $result->getPayload());
        $this->assertLessThan(0.1, $elapsed); // Should return immediately
    }

    public function test_blocking_pop_returns_null_after_timeout(): void
    {
        /** @var RedisQueue $queue */
        $queue = $this->queue;

        $start = microtime(true);
        $result = $queue->blockingPop('empty-blocking-queue', 1);
        $elapsed = microtime(true) - $start;

        $this->assertNull($result);
        $this->assertGreaterThanOrEqual(1, $elapsed);
        $this->assertLessThan(2, $elapsed);
    }
}
