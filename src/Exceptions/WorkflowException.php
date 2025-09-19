<?php

namespace WorkflowOrchestrator\Exceptions;

use Exception;
use Throwable;

class WorkflowException extends Exception
{
    public function __construct(
        string $message = "", int $code = 0, ?Throwable $previous = null, private ?string $failedStep = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getFailedStep(): ?string
    {
        return $this->failedStep;
    }
}
