<?php

namespace WorkflowOrchestrator\Contracts;

/**
 * Simple container interface for dependency injection
 * Compatible with PSR-11 but simplified for our needs
 */
interface ContainerInterface
{
    public function get(string $id): object;
    public function has(string $id): bool;
}
