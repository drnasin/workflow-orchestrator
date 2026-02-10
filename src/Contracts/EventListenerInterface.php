<?php

namespace WorkflowOrchestrator\Contracts;

use Throwable;
use WorkflowOrchestrator\Message\WorkflowMessage;

interface EventListenerInterface
{
    public function onStepStarted(string $stepName, WorkflowMessage $message): void;

    public function onStepCompleted(string $stepName, WorkflowMessage $message, float $duration): void;

    public function onStepFailed(string $stepName, WorkflowMessage $message, Throwable $error, float $duration): void;
}
