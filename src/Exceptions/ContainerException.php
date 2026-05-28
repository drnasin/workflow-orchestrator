<?php
namespace WorkflowOrchestrator\Exceptions;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown by {@see \WorkflowOrchestrator\Container\SimpleContainer::get()} when
 * no entry can be resolved for the requested id. Implements PSR-11's
 * NotFoundExceptionInterface (which itself extends ContainerExceptionInterface)
 * so PSR-11 consumers can catch the standard contract.
 */
class ContainerException extends Exception implements NotFoundExceptionInterface {}