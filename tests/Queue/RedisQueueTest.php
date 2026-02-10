<?php

namespace WorkflowOrchestrator\Tests\Queue;

use PHPUnit\Framework\TestCase;
use Redis;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\Queue\RedisQueue;

class RedisQueueTest extends TestCase
{
    private Redis $redis;
    private RedisQueue $queue;

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

    public function test_handles_complex_payloads(): void
    {
        $complexPayload = [
            'id' => 123,
            'data' => ['nested' => ['array' => true]],
            'items' => [1, 2, 3, 4, 5]
        ];
        
        $message = new WorkflowMessage($complexPayload, ['step1'], ['context' => 'test']);
        
        $this->queue->push('complex-queue', $message);
        $retrieved = $this->queue->pop('complex-queue');
        
        $this->assertEquals($complexPayload, $retrieved->getPayload());
        $this->assertSame(['step1'], $retrieved->getSteps());
        $this->assertSame(['context' => 'test'], $retrieved->getAllHeaders());
    }

    public function test_peek_returns_first_message_without_removing(): void
    {
        $message1 = new WorkflowMessage('payload1', [], []);
        $message2 = new WorkflowMessage('payload2', [], []);
        
        $this->queue->push('peek-queue', $message1);
        $this->queue->push('peek-queue', $message2);
        
        $peeked = $this->queue->peek('peek-queue');
        $this->assertSame('payload1', $peeked->getPayload());
        $this->assertSame(2, $this->queue->size('peek-queue'));
        
        // Peek again should return same message
        $peekedAgain = $this->queue->peek('peek-queue');
        $this->assertSame('payload1', $peekedAgain->getPayload());
        $this->assertSame(2, $this->queue->size('peek-queue'));
        
        // Pop should return the peeked message
        $popped = $this->queue->pop('peek-queue');
        $this->assertSame('payload1', $popped->getPayload());
        $this->assertSame(1, $this->queue->size('peek-queue'));
    }

    public function test_peek_returns_null_for_empty_queue(): void
    {
        $result = $this->queue->peek('empty-queue');
        $this->assertNull($result);
    }

    public function test_get_queue_names_returns_active_queues(): void
    {
        $this->queue->push('queue1', new WorkflowMessage('test1', [], []));
        $this->queue->push('queue2', new WorkflowMessage('test2', [], []));
        $this->queue->push('queue3', new WorkflowMessage('test3', [], []));
        
        $queueNames = $this->queue->getQueueNames();
        
        $this->assertCount(3, $queueNames);
        $this->assertContains('queue1', $queueNames);
        $this->assertContains('queue2', $queueNames);
        $this->assertContains('queue3', $queueNames);
    }

    public function test_blocking_pop_returns_immediately_if_message_available(): void
    {
        $message = new WorkflowMessage('blocking-test', [], []);
        $this->queue->push('blocking-queue', $message);
        
        $start = microtime(true);
        $result = $this->queue->blockingPop('blocking-queue', 1);
        $elapsed = microtime(true) - $start;
        
        $this->assertNotNull($result);
        $this->assertSame('blocking-test', $result->getPayload());
        $this->assertLessThan(0.1, $elapsed); // Should return immediately
    }

    public function test_blocking_pop_returns_null_after_timeout(): void
    {
        $start = microtime(true);
        $result = $this->queue->blockingPop('empty-blocking-queue', 1);
        $elapsed = microtime(true) - $start;
        
        $this->assertNull($result);
        $this->assertGreaterThanOrEqual(1, $elapsed);
        $this->assertLessThan(2, $elapsed);
    }
}