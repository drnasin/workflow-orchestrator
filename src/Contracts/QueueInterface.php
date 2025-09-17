<?php

namespace WorkflowOrchestrator\Contracts;

use WorkflowOrchestrator\Message\WorkflowMessage;

interface QueueInterface
{
    public function push(string $queue, WorkflowMessage $message): void;

    public function pop(string $queue): ?WorkflowMessage;
}
