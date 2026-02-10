<?php

namespace WorkflowOrchestrator\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
readonly class Orchestrator
{
    public function __construct(public string $channel)
    {
        if (trim($this->channel) === '') {
            throw new InvalidArgumentException('Orchestrator channel name cannot be empty');
        }
    }
}