<?php
namespace WorkflowOrchestrator\Contracts;

use WorkflowOrchestrator\Message\WorkflowMessage;

interface MiddlewareInterface
{
    public function handle(WorkflowMessage $message, callable $next): WorkflowMessage;
}