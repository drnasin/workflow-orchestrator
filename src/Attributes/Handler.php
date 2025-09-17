<?php

namespace WorkflowOrchestrator\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
readonly class Handler
{
    public function __construct(public string $channel, public bool $async = false, public bool $returnsHeaders = false)
    {
    }
}