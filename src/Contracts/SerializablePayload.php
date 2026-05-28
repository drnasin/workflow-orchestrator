<?php

namespace WorkflowOrchestrator\Contracts;

/**
 * Opt-in contract for workflow payloads that must survive JSON-serialized queues
 * (SqliteQueue, RedisQueue) with their type intact.
 *
 * The library uses JSON — not PHP's serialize() — for queue persistence to avoid
 * object-injection vulnerabilities. As a result, async payloads round-trip to
 * associative arrays by default, which breaks `param instanceof MyType` resolution
 * in handlers typed on the payload class.
 *
 * Classes that implement this interface are rehydrated to their original type on
 * pop(): WorkflowMessage records the class name alongside the data, and
 * fromArray() reconstructs the instance via {@see fromArray()} — but only when
 * the stored class name still implements this interface. The interface itself
 * acts as a safe whitelist: arbitrary class names from a queue payload cannot
 * trigger construction of classes the application did not explicitly opt in.
 *
 * Plain scalar or array payloads do not need this interface; they round-trip
 * faithfully through JSON without help.
 */
interface SerializablePayload
{
    /**
     * Returns the JSON-safe representation of this payload. Must be deterministic
     * and contain only scalars, arrays, or other SerializablePayload values.
     */
    public function toArray(): array;

    /**
     * Reconstructs an instance from the array produced by {@see toArray()}.
     */
    public static function fromArray(array $data): static;
}
