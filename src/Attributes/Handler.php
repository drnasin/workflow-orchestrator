<?php

namespace WorkflowOrchestrator\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
readonly class Handler
{
    public function __construct(
        public string $channel,
        public bool $async = false,
        public bool $returnsHeaders = false,
        public int $timeout = 0,
    ) {
        if (trim($this->channel) === '') {
            throw new InvalidArgumentException('Handler channel name cannot be empty');
        }
    }
}