<?php
namespace WorkflowOrchestrator\Contracts;

use WorkflowOrchestrator\Message\WorkflowMessage;

/**
 * Pre-processes a workflow message before any step runs.
 *
 * Middleware is applied exactly once, at the workflow entry point, before the
 * step loop begins — it does NOT wrap step execution. Implementations may
 * inspect or transform the message and must call $next($message) to pass control
 * down the pipeline; the returned message is what the steps then run against.
 * Because it runs before the steps, middleware cannot observe step results,
 * time the overall run, or execute logic after the steps complete.
 */
interface MiddlewareInterface
{
    public function handle(WorkflowMessage $message, callable $next): WorkflowMessage;
}