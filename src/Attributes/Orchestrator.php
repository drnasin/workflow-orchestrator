<?php

namespace WorkflowOrchestrator\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
readonly class Orchestrator
{
    public function __construct(public string $channel, public bool $async = false)
    {
    }
}