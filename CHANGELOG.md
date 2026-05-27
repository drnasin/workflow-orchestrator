# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- `SqliteQueue` constructor parameter `$busyTimeoutMs` (default `5000`): concurrent workers wait for the write lock instead of failing immediately with `SQLITE_BUSY`. Pass `0` to disable the wait (fail-fast).
- PHP 8.5 to the CI test matrix (Linux, macOS, Windows).

### Fixed
- `SqliteQueue::pop()` is now concurrency-safe. It uses `BEGIN IMMEDIATE` so two workers can no longer read and claim the same message (previously this surfaced as uncaught `SQLITE_BUSY` under the default `ERRMODE_EXCEPTION`, or silent duplicate delivery under `SILENT`/`WARNING`). `COMMIT`/`ROLLBACK` are issued as raw SQL for PHP 8.3 compatibility (PHP < 8.4 does not track transactions started via `PDO::exec('BEGIN ...')`).
- Middleware is no longer re-applied on async workflow continuation. It now runs exactly once per workflow, at the entry point, instead of again on every queued-step resumption.
- Step failures are now reported through `EventListenerInterface::onStepFailed()` exactly once, including step-scoped `WorkflowException`s (timeouts and exceptions propagated from nested workflows) that were previously skipped by the early re-throw.

### Changed
- `SimpleContainer` no longer caches auto-resolved instances: a class created via auto-resolution is constructed fresh on every `get()`. This prevents workflow handlers and orchestrators from becoming de-facto singletons and leaking per-execution state across workflows in long-running workers. Services that must be shared are still registered explicitly via `set()` (objects and factory closures remain singletons).
- `HandlerRegistry` now throws a `WorkflowException` when two different methods try to claim the same channel, instead of silently overwriting the first registration. Re-registering the exact same class+method remains idempotent.
- A handler declared with `returnsHeaders: true` that returns a non-array value now throws a `WorkflowException` instead of silently discarding the value as empty headers.

### Documented
- `MiddlewareInterface` is clarified as a pre-processor that runs once before the steps; it does not wrap step execution and cannot observe step results.

## [v1.5.0]

### Changed
- Restructured README to flow from simple to advanced usage: features, value proposition, error handling, and testing now appear before advanced topics
- Grouped all advanced topics (dynamic workflows, headers, async, timeouts, middleware, event listeners) under a single "Advanced Usage" section
- Extracted version history from README into dedicated CHANGELOG.md using Keep a Changelog format

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
