# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [v1.4.0]

### Fixed
- `WorkflowOrchestrator::processAsyncStep()` now exposes the `maxRetries` parameter (was only available via the engine directly)
- Removed unused `Orchestrator::async` property — it was accepted but never read
- `WorkflowMessage::fromArray()` now validates that the `payload` key exists, throwing `InvalidArgumentException` for malformed data
- `SqliteQueue::pop()` now catches `Throwable` instead of `Exception` for consistency with the rest of the codebase

### Tests
- Added middleware execution ordering test (verifies FIFO order across multiple `withMiddleware()` calls)
- Added `SimpleContainer` tests for classes with required constructor parameters (100% method coverage)

## [v1.3.0]

### Improved
- Completed `QueueInterface` with `size()` and `clear()` methods — now part of the contract, not just implementation details
- Extracted `_retry_attempt` magic string to a private constant in `WorkflowEngine`
- Consolidated duplicate exception handling in `executeStep()` into a single catch block
- Cached `ReflectionMethod` objects in `invokeMethod()` to avoid redundant reflection on repeated step invocations
- Extracted shared queue test base class (`AbstractQueueTest`) — eliminates ~80 lines of duplicated test code across `DatabaseQueueTest` and `RedisQueueTest`

## [v1.2.0]

### Added
- Step timeout support: `#[Handler(channel: 'step', timeout: 30)]` enforces wall-clock time limits on handlers
- Event listener system: `EventListenerInterface` with `onStepStarted`, `onStepCompleted`, and `onStepFailed` hooks for observability
- `WorkflowOrchestrator::withEventListener()` for adding listeners via the facade (immutable, chainable)
- Attribute validation: `Handler` and `Orchestrator` now reject empty channel names at construction

### Improved
- Moved `ext-pdo` from `require` to `suggest` in `composer.json` — only needed for `SqliteQueue`
- Handler registry now stores timeout metadata

## [v1.1.0]

### Added
- Async retry logic: `processAsyncStep()` now supports configurable `maxRetries` (default 3) with automatic re-queuing of failed messages
- `WorkflowOrchestrator::withMiddleware()` for adding middleware via the facade (immutable, chainable)
- Cryptographically secure workflow IDs using `random_bytes()` instead of `uniqid()`

### Fixed
- Parameter resolution now throws a clear `WorkflowException` when a typed parameter cannot be resolved, instead of silently passing the wrong type

## [v1.0.0]

### Security
- Replaced unsafe `serialize()`/`unserialize()` with JSON encoding in `RedisQueue` and `SqliteQueue` to prevent object injection attacks
- Added table name validation in `SqliteQueue` constructor to prevent SQL injection via malicious table names

### Improved
- Added `WorkflowMessage::toArray()` and `WorkflowMessage::fromArray()` for safe, portable message serialization
- Redis tests now gracefully skip (instead of erroring) when Redis server is unavailable
