<?php

namespace WorkflowOrchestrator\Queue;

use Redis;
use WorkflowOrchestrator\Contracts\QueueInterface;
use WorkflowOrchestrator\Message\WorkflowMessage;

class RedisQueue implements QueueInterface
{
    public function __construct(private readonly Redis $redis, private string $keyPrefix = 'workflow_queue:')
    {
    }

    public function push(string $queue, WorkflowMessage $message): void
    {
        $key = $this->getQueueKey($queue);
        $encodedMessage = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

        $this->redis->rPush($key, $encodedMessage);
    }

    public function pop(string $queue): ?WorkflowMessage
    {
        $key = $this->getQueueKey($queue);
        $encodedMessage = $this->redis->lPop($key);

        if (!$encodedMessage) {
            return null;
        }

        return WorkflowMessage::fromArray(json_decode($encodedMessage, true, 512, JSON_THROW_ON_ERROR));
    }

    public function size(string $queue): int
    {
        $key = $this->getQueueKey($queue);
        return $this->redis->lLen($key);
    }

    public function clear(string $queue): void
    {
        $key = $this->getQueueKey($queue);
        $this->redis->del($key);
    }

    public function peek(string $queue): ?WorkflowMessage
    {
        $key = $this->getQueueKey($queue);
        $encodedMessage = $this->redis->lIndex($key, 0);

        if ($encodedMessage === false || $encodedMessage === null) {
            return null;
        }

        return WorkflowMessage::fromArray(json_decode($encodedMessage, true, 512, JSON_THROW_ON_ERROR));
    }

    public function blockingPop(string $queue, int $timeout = 0): ?WorkflowMessage
    {
        $key = $this->getQueueKey($queue);
        $result = $this->redis->blPop([$key], $timeout);

        if (empty($result)) {
            return null;
        }

        return WorkflowMessage::fromArray(json_decode($result[1], true, 512, JSON_THROW_ON_ERROR));
    }

    public function getQueueNames(): array
    {
        $pattern = $this->keyPrefix . '*';
        $keys = $this->redis->keys($pattern);

        return array_map(
            fn($key) => substr($key, strlen($this->keyPrefix)),
            $keys
        );
    }

    private function getQueueKey(string $queue): string
    {
        return $this->keyPrefix . $queue;
    }
}
