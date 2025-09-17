<?php

namespace WorkflowOrchestrator\Exceptions;

use Exception;
use Throwable;

class WorkflowException extends Exception
{
    private ?string $failedStep = null;

    public function __construct(
        string $message = "", int $code = 0, ?Throwable $previous = null, ?string $failedStep = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->failedStep = $failedStep;
    }

    public function getFailedStep(): ?string
    {
        return $this->failedStep;
    }
}
