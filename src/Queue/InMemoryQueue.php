<?php
namespace WorkflowOrchestrator\Queue;

use WorkflowOrchestrator\Contracts\QueueInterface;
use WorkflowOrchestrator\Message\WorkflowMessage;

class InMemoryQueue implements QueueInterface
{
    private array $queues = [];

    public function push(string $queue, WorkflowMessage $message): void
    {
        if (!isset($this->queues[$queue])) {
            $this->queues[$queue] = [];
        }

        $this->queues[$queue][] = $message;
    }

    public function pop(string $queue): ?WorkflowMessage
    {
        if (empty($this->queues[$queue])) {
            return null;
        }

        return array_shift($this->queues[$queue]);
    }

    public function size(string $queue): int
    {
        return count($this->queues[$queue] ?? []);
    }

    public function clear(string $queue): void
    {
        $this->queues[$queue] = [];
    }
}