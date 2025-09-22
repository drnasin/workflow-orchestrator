<?php

namespace WorkflowOrchestrator\Queue;

use Redis;
use WorkflowOrchestrator\Contracts\QueueInterface;
use WorkflowOrchestrator\Message\WorkflowMessage;

class RedisQueue implements QueueInterface
{
    private string $keyPrefix;

    public function __construct(private Redis $redis, string $keyPrefix = 'workflow_queue:')
    {
        $this->keyPrefix = $keyPrefix;
    }

    public function push(string $queue, WorkflowMessage $message): void
    {
        $key = $this->getQueueKey($queue);
        $serializedMessage = serialize($message);
        
        $this->redis->rPush($key, $serializedMessage);
    }

    public function pop(string $queue): ?WorkflowMessage
    {
        $key = $this->getQueueKey($queue);
        $serializedMessage = $this->redis->lPop($key);
        
        if (!$serializedMessage) {
            return null;
        }
        
        return unserialize($serializedMessage);
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
        $serializedMessage = $this->redis->lIndex($key, 0);
        
        if ($serializedMessage === false || $serializedMessage === null) {
            return null;
        }
        
        return unserialize($serializedMessage);
    }

    public function blockingPop(string $queue, int $timeout = 0): ?WorkflowMessage
    {
        $key = $this->getQueueKey($queue);
        $result = $this->redis->blPop([$key], $timeout);
        
        if (empty($result)) {
            return null;
        }
        
        return unserialize($result[1]);
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