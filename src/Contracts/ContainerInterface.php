<?php

namespace WorkflowOrchestrator\Contracts;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Container interface for dependency injection. Extends the PSR-11 contract so
 * any PSR-11 container can be passed where this interface is expected, and any
 * implementation of this interface satisfies PSR-11 consumers.
 *
 * The local return type for {@see get()} is narrowed to `object` because every
 * resolvable entry in this library is a class instance; PSR-11 itself returns
 * `mixed`, and narrowing in an extending interface is allowed.
 */
interface ContainerInterface extends PsrContainerInterface
{
    public function get(string $id): object;
    public function has(string $id): bool;
}
