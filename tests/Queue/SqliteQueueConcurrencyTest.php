<?php

namespace WorkflowOrchestrator\Tests\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;
use WorkflowOrchestrator\Message\WorkflowMessage;
use WorkflowOrchestrator\Queue\SqliteQueue;

/**
 * Concurrency-focused tests for SqliteQueue.
 *
 * These use a shared on-disk database (not :memory:, which is per-connection)
 * so two independent PDO connections model two competing workers.
 */
class SqliteQueueConcurrencyTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/wfq_concurrency_' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (isset($this->dbFile) && file_exists($this->dbFile)) {
            @unlink($this->dbFile);
        }
    }

    private function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function test_two_workers_never_pop_the_same_message_twice(): void
    {
        $producer = new SqliteQueue($this->connect());
        $producer->push('jobs', new WorkflowMessage('only-one', ['step'], []));

        $workerA = new SqliteQueue($this->connect());
        $workerB = new SqliteQueue($this->connect());

        $a = $workerA->pop('jobs');
        $b = $workerB->pop('jobs');

        $claimed = array_filter([$a, $b]);

        $this->assertCount(1, $claimed, 'Exactly one worker must claim the message (no duplicate, no loss)');
        $this->assertSame('only-one', array_values($claimed)[0]->getPayload());
        $this->assertSame(0, $producer->size('jobs'));
    }

    public function test_no_message_is_lost_or_duplicated_under_alternating_workers(): void
    {
        $producer = new SqliteQueue($this->connect());
        for ($i = 0; $i < 20; $i++) {
            $producer->push('jobs', new WorkflowMessage("payload-$i", [], []));
        }

        $workerA = new SqliteQueue($this->connect());
        $workerB = new SqliteQueue($this->connect());

        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $worker = $i % 2 === 0 ? $workerA : $workerB;
            $message = $worker->pop('jobs');
            $this->assertNotNull($message);
            $seen[] = $message->getPayload();
        }

        $this->assertNull($workerA->pop('jobs'));
        $this->assertNull($workerB->pop('jobs'));
        $this->assertCount(20, array_unique($seen), 'No payload may be delivered more than once');
    }

    public function test_pop_waits_or_fails_fast_when_write_lock_is_held(): void
    {
        $producer = new SqliteQueue($this->connect());
        $producer->push('jobs', new WorkflowMessage('locked', [], []));

        // Hold the write lock on a raw connection, simulating an in-progress pop()
        // by another worker.
        $holder = $this->connect();
        $holder->exec('BEGIN IMMEDIATE');
        $holder->exec('CREATE TABLE IF NOT EXISTS _touch (n INTEGER)');
        $holder->exec('INSERT INTO _touch (n) VALUES (1)');

        // Fail-fast worker (busy_timeout disabled) must not silently return null;
        // it must surface the lock contention rather than risk a wrong empty read.
        $failFast = new SqliteQueue($this->connect(), busyTimeoutMs: 0);

        $threw = false;
        try {
            $failFast->pop('jobs');
        } catch (Throwable $e) {
            $threw = true;
            $this->assertStringContainsStringIgnoringCase('locked', $e->getMessage());
        }

        $this->assertTrue($threw, 'A fail-fast pop must throw on lock contention, never return a wrong null');

        // Release the lock; the message must still be there and claimable exactly once.
        $holder->exec('ROLLBACK');

        $message = $failFast->pop('jobs');
        $this->assertNotNull($message);
        $this->assertSame('locked', $message->getPayload());
    }

    public function test_busy_timeout_zero_does_not_break_normal_operation(): void
    {
        $queue = new SqliteQueue($this->connect(), busyTimeoutMs: 0);

        $queue->push('jobs', new WorkflowMessage('payload', ['s'], ['h' => 'v']));

        $message = $queue->pop('jobs');
        $this->assertNotNull($message);
        $this->assertSame('payload', $message->getPayload());
        $this->assertNull($queue->pop('jobs'));
    }
}
