[![Tests](https://github.com/drnasin/workflow-orchestrator/actions/workflows/tests.yml/badge.svg)](https://github.com/drnasin/workflow-orchestrator/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.3-8892BF.svg)](https://php.net)

# Workflow Orchestrator

A lightweight, stateless workflow orchestration library for PHP 8.3+.

## Installation

```bash
composer require drnasin/workflow-orchestrator
```

## Quick Start

### 1. Define Your Workflow

```php
use WorkflowOrchestrator\WorkflowOrchestrator;
use WorkflowOrchestrator\Attributes\Orchestrator;
use WorkflowOrchestrator\Attributes\Handler;

class OrderProcessor
{
    #[Orchestrator(channel: 'process.order')]
    public function processOrder(Order $order): array
    {
        $steps = ['validate', 'payment'];
        
        if ($order->getCustomer()->isPremium()) {
            $steps[] = 'apply-discount';
        }
        
        $steps[] = 'confirmation';
        return $steps;
    }

    #[Handler(channel: 'validate')]
    public function validate(Order $order): Order
    {
        // Validation logic
        if (!$order->hasItems()) {
            throw new \InvalidArgumentException('Order must have items');
        }
        return $order;
    }

    #[Handler(channel: 'payment')]
    public function processPayment(Order $order): Order
    {
        // Payment processing logic
        $order->markAsPaid();
        return $order;
    }

    #[Handler(channel: 'apply-discount')]
    public function applyDiscount(Order $order): Order
    {
        // Apply premium discount
        $order->applyDiscount(0.15);
        return $order;
    }

    #[Handler(channel: 'confirmation')]
    public function sendConfirmation(Order $order): Order
    {
        // Send confirmation email
        mail($order->getCustomer()->getEmail(), 'Order Confirmed', '...');
        return $order;
    }
}
```

### 2. Execute the Workflow

```php
// Simple usage
$orchestrator = WorkflowOrchestrator::create()
    ->register(OrderProcessor::class);

$result = $orchestrator->execute('process.order', $order);
```

That's it! The workflow will:
1. Validate the order
2. Process payment
3. Apply discount (if premium customer)
4. Send confirmation

## Features

- ✅ **Stateless workflow execution** - No database storage required
- ✅ **Dynamic step routing** - Workflows adapt based on data
- ✅ **Async step processing** - Heavy operations run in background with configurable retry
- ✅ **Header/metadata support** - Pass context between steps
- ✅ **Middleware support** - Add cross-cutting concerns
- ✅ **Step timeouts** - Enforce time limits on individual handlers
- ✅ **Event listeners** - Track step execution for logging, metrics, and monitoring
- ✅ **Clean PHP 8+ API** - Uses modern attributes and readonly properties
- ✅ **Container integration** - Works with any PSR-11 container
- ✅ **Zero dependencies** - Only requires PSR interfaces

## Advanced Usage

### Async Steps

For heavy processing, mark steps as async:

```php
#[Handler(channel: 'generate-invoice', async: true)]
public function generateInvoice(Order $order): Order
{
    // This will be queued for background processing
    $this->invoiceGenerator->generate($order);
    return $order;
}
```

Process queued steps with configurable retry:

```php
// Process with default 3 retries
$orchestrator->processAsyncStep('generate-invoice');

// Process with custom retry limit
$orchestrator->processAsyncStep('generate-invoice', maxRetries: 5);

// No retries — fail immediately on error
$orchestrator->processAsyncStep('generate-invoice', maxRetries: 0);
```

Failed steps are automatically re-queued until retries are exhausted, then a `WorkflowException` is thrown.

### Step Timeouts

Enforce time limits on individual handlers:

```php
#[Handler(channel: 'call-external-api', timeout: 30)]
public function callExternalApi(Order $order): Order
{
    // If this takes longer than 30 seconds, a WorkflowException is thrown
    $response = $this->apiClient->submit($order);
    return $order->withResponse($response);
}
```

The timeout is measured in seconds using wall-clock time. A value of `0` (the default) means no time limit.

### Headers and Context

Pass metadata between steps without modifying your main payload:

```php
#[Handler(channel: 'enrich-customer-data', returnsHeaders: true)]
public function enrichCustomerData(Order $order): array
{
    return [
        'customer_tier' => $order->getCustomer()->getTier(),
        'loyalty_points' => $order->getCustomer()->getLoyaltyPoints(),
        'region' => $order->getShippingAddress()->getCountry(),
    ];
}

#[Handler(channel: 'apply-regional-pricing')]
public function applyRegionalPricing(
    Order $order,
    #[Header('customer_tier')] string $tier,
    #[Header('region')] string $region
): Order {
    // Use header values for business logic
    $discount = $this->calculateRegionalDiscount($tier, $region);
    return $order->applyDiscount($discount);
}
```

### Dynamic Workflows

Build different workflows based on your business rules:

```php
#[Orchestrator(channel: 'process.user.registration')]
public function processUserRegistration(User $user): array
{
    $steps = ['validate-email', 'create-account'];
    
    // Different flow for enterprise users
    if ($user->isEnterprise()) {
        $steps[] = 'setup-organization';
        $steps[] = 'assign-account-manager';
        $steps[] = 'configure-billing';
    } else {
        $steps[] = 'setup-trial';
        $steps[] = 'send-welcome-email';
    }
    
    // International users need additional steps
    if ($user->isInternational()) {
        $steps[] = 'setup-localization';
        $steps[] = 'configure-timezone';
    }
    
    $steps[] = 'activate-account';
    $steps[] = 'track-registration-metrics';
    
    return $steps;
}
```

### Middleware

Add cross-cutting concerns that run before every workflow:

```php
use WorkflowOrchestrator\Contracts\MiddlewareInterface;
use WorkflowOrchestrator\Message\WorkflowMessage;

class LoggingMiddleware implements MiddlewareInterface
{
    public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
    {
        Log::info('Workflow started', ['id' => $message->getId()]);
        return $next($message);
    }
}

class AuthorizationMiddleware implements MiddlewareInterface
{
    public function handle(WorkflowMessage $message, callable $next): WorkflowMessage
    {
        if (!$message->getHeader('authorized')) {
            throw new \RuntimeException('Unauthorized workflow execution');
        }
        return $next($message);
    }
}

$orchestrator = WorkflowOrchestrator::create()
    ->withMiddleware(new LoggingMiddleware())
    ->withMiddleware(new AuthorizationMiddleware())
    ->register(OrderProcessor::class);
```

Middleware executes in the order added. Each call to `withMiddleware()` returns a new immutable instance.

### Event Listeners

Track step execution for logging, metrics, or monitoring:

```php
use WorkflowOrchestrator\Contracts\EventListenerInterface;
use WorkflowOrchestrator\Message\WorkflowMessage;

class MetricsListener implements EventListenerInterface
{
    public function onStepStarted(string $stepName, WorkflowMessage $message): void
    {
        Metrics::increment("workflow.step.{$stepName}.started");
    }

    public function onStepCompleted(string $stepName, WorkflowMessage $message, float $duration): void
    {
        Metrics::timing("workflow.step.{$stepName}.duration", $duration);
        Metrics::increment("workflow.step.{$stepName}.completed");
    }

    public function onStepFailed(string $stepName, WorkflowMessage $message, \Throwable $error, float $duration): void
    {
        Metrics::increment("workflow.step.{$stepName}.failed");
        Log::error("Step {$stepName} failed after {$duration}s", [
            'error' => $error->getMessage(),
            'workflow_id' => $message->getId(),
        ]);
    }
}

$orchestrator = WorkflowOrchestrator::create()
    ->withEventListener(new MetricsListener())
    ->register(OrderProcessor::class);
```

Events fire for every step execution, including async steps. The `$duration` parameter is measured in seconds with nanosecond precision.

## Container Integration

Use with your preferred dependency injection container:

```php
use Psr\Container\ContainerInterface;

// With PSR-11 container
$orchestrator = new WorkflowOrchestrator($myContainer);

// With custom queue for async processing
$orchestrator = new WorkflowOrchestrator(
    container: $myContainer,
    queue: new RedisQueue($redisConnection)
);
```

## Queue Implementations

The Workflow Orchestrator supports multiple queue implementations for async step processing. Choose the one that best fits your infrastructure needs.

### In-Memory Queue (Default)

The default queue implementation stores messages in memory and is suitable for development and testing:

```php
use WorkflowOrchestrator\Queue\InMemoryQueue;

$orchestrator = WorkflowOrchestrator::create()
    ->withQueue(new InMemoryQueue())
    ->register(OrderProcessor::class);
```

**Note:** In-memory queues lose data when the process ends and don't support distributed processing.

### SQLite Queue

For persistent storage without external dependencies, use the SQLite queue:

```php
use WorkflowOrchestrator\Queue\SqliteQueue;

// Create PDO connection
$pdo = new PDO('sqlite:/path/to/your/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create SQLite queue
$queue = new SqliteQueue($pdo);

$orchestrator = WorkflowOrchestrator::create()
    ->withQueue($queue)
    ->register(OrderProcessor::class);
```

**Features:**
- ✅ Persistent storage
- ✅ ACID transactions
- ✅ Automatic table creation
- ✅ No external dependencies
- ✅ Single-file database

**Setup Requirements:**
- PHP PDO extension (included by default)
- Write permissions for SQLite database file

**Custom Table Name:**
```php
$queue = new SqliteQueue($pdo, 'my_custom_queue_table');
```

### Redis Queue

For high-performance, distributed queue processing:

```php
use WorkflowOrchestrator\Queue\RedisQueue;

// Create Redis connection
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
// Optional: $redis->auth('your-password');
// Optional: $redis->select(1); // Use specific database

// Create Redis queue
$queue = new RedisQueue($redis);

$orchestrator = WorkflowOrchestrator::create()
    ->withQueue($queue)
    ->register(OrderProcessor::class);
```

**Features:**
- ✅ High performance
- ✅ Distributed processing
- ✅ Blocking operations
- ✅ Multiple queue support
- ✅ Automatic cleanup
- ✅ Queue introspection

**Setup Requirements:**
- Redis server
- PHP Redis extension: `composer require ext-redis` or install via system package manager

**Install Redis Extension:**
```bash
# Ubuntu/Debian
sudo apt-get install php-redis

# macOS (via Homebrew)
brew install php
pecl install redis

# Windows (via PECL)
pecl install redis
```

**Custom Key Prefix:**
```php
$queue = new RedisQueue($redis, 'my_app:queue:');
```

**Advanced Redis Usage:**
```php
// Blocking pop with timeout
$message = $queue->blockingPop('high-priority', 30); // 30 second timeout

// Check queue size
$size = $queue->size('email-notifications');

// Get all queue names
$queues = $queue->getQueueNames();

// Peek at next message without removing it
$nextMessage = $queue->peek('data-processing');
```

### Queue Selection Guide

| Feature | InMemory | SQLite | Redis |
|---------|----------|--------|-------|
| Persistence | ❌ | ✅ | ✅ |
| Distributed | ❌ | ❌ | ✅ |
| Performance | ⚡⚡⚡ | ⚡⚡ | ⚡⚡⚡ |
| Setup Complexity | ⚡⚡⚡ | ⚡⚡ | ⚡ |
| External Dependencies | ❌ | ❌ | ✅ |
| Blocking Operations | ❌ | ❌ | ✅ |

**Recommendations:**
- **Development/Testing:** InMemoryQueue
- **Single-server production:** SqliteQueue
- **Multi-server/high-volume:** RedisQueue

### Custom Queue Implementation

Implement your own queue by creating a class that implements `QueueInterface`:

```php
use WorkflowOrchestrator\Contracts\QueueInterface;
use WorkflowOrchestrator\Message\WorkflowMessage;

class MyCustomQueue implements QueueInterface
{
    public function push(string $queue, WorkflowMessage $message): void
    {
        // Your implementation
    }

    public function pop(string $queue): ?WorkflowMessage
    {
        // Your implementation
    }

    public function size(string $queue): int
    {
        // Your implementation
    }

    public function clear(string $queue): void
    {
        // Your implementation
    }
}
```

## Error Handling

Workflow steps that fail are automatically wrapped with context:

```php
try {
    $result = $orchestrator->execute('process.order', $order);
} catch (WorkflowException $e) {
    // Exception message: "Step 'payment' failed: Card declined"
    Log::error('Workflow failed', [
        'step' => $e->getFailedStep(), // Available if you extend the exception
        'message' => $e->getMessage(),
        'original' => $e->getPrevious(),
    ]);
}
```

## Testing

Test workflows in isolation:

```php
use PHPUnit\Framework\TestCase;

class OrderProcessorTest extends TestCase
{
    public function test_premium_customer_gets_discount(): void
    {
        $container = new SimpleContainer();
        $orchestrator = WorkflowOrchestrator::create($container)
            ->register(OrderProcessor::class);
            
        $order = new Order($premiumCustomer, $items);
        
        $result = $orchestrator->execute('process.order', $order);
        
        $this->assertTrue($result->hasDiscount());
        $this->assertTrue($result->isPaid());
    }
    
    public function test_regular_customer_no_discount(): void
    {
        $container = new SimpleContainer();
        $orchestrator = WorkflowOrchestrator::create($container)
            ->register(OrderProcessor::class);
            
        $order = new Order($regularCustomer, $items);
        
        $result = $orchestrator->execute('process.order', $order);
        
        $this->assertFalse($result->hasDiscount());
        $this->assertTrue($result->isPaid());
    }
}
```

## Why Workflow Orchestrator?

### Traditional Approach Problems
```php
// ❌ Hard to maintain, test, and modify
class OrderService
{
    public function process(Order $order): void
    {
        $this->validate($order);
        $this->processPayment($order);     // What if this fails?
        $this->updateInventory($order);    // What if this is slow?
        $this->sendEmail($order);          // What if email is down?
        $this->generateInvoice($order);    // Getting complex...
    }
}
```

### Workflow Orchestrator Approach
```php
// ✅ Clear, testable, and flexible
#[Orchestrator(channel: 'process.order')]
public function processOrder(Order $order): array
{
    return ['validate', 'payment', 'inventory', 'email', 'invoice'];
}

// Each step is isolated, testable, and can be async
#[Handler(channel: 'email', async: true)]
public function sendEmail(Order $order): Order { ... }
```

## Benefits Over State Machines

- **Focus on behavior, not state** - Define what happens, not state transitions
- **No database storage** - Workflows are stateless and self-contained
- **Zero migrations** - Deploy workflow changes instantly
- **Easy testing** - Each step is independently testable
- **Horizontal scaling** - Any server can process any step

## Performance

- **Stateless execution** - No database queries for workflow state
- **Async support** - Heavy operations don't block
- **Memory efficient** - No persistent workflow instances
- **Scales horizontally** - Add more servers without coordination

## Requirements

- PHP 8.3+
- PSR-11 container (optional, includes simple container)
- `ext-pdo` for SqliteQueue
- `ext-redis` for RedisQueue (optional)

## Changelog

### v1.2.0

**New Features:**
- Step timeout support: `#[Handler(channel: 'step', timeout: 30)]` enforces wall-clock time limits on handlers
- Event listener system: `EventListenerInterface` with `onStepStarted`, `onStepCompleted`, and `onStepFailed` hooks for observability
- `WorkflowOrchestrator::withEventListener()` for adding listeners via the facade (immutable, chainable)
- Attribute validation: `Handler` and `Orchestrator` now reject empty channel names at construction

**Improvements:**
- Moved `ext-pdo` from `require` to `suggest` in `composer.json` — only needed for `SqliteQueue`
- Handler registry now stores timeout metadata

### v1.1.0

**New Features:**
- Async retry logic: `processAsyncStep()` now supports configurable `maxRetries` (default 3) with automatic re-queuing of failed messages
- `WorkflowOrchestrator::withMiddleware()` for adding middleware via the facade (immutable, chainable)
- Cryptographically secure workflow IDs using `random_bytes()` instead of `uniqid()`

**Bug Fixes:**
- Parameter resolution now throws a clear `WorkflowException` when a typed parameter cannot be resolved, instead of silently passing the wrong type

### v1.0.0

**Security Fixes:**
- Replaced unsafe `serialize()`/`unserialize()` with JSON encoding in `RedisQueue` and `SqliteQueue` to prevent object injection attacks
- Added table name validation in `SqliteQueue` constructor to prevent SQL injection via malicious table names

**Improvements:**
- Added `WorkflowMessage::toArray()` and `WorkflowMessage::fromArray()` for safe, portable message serialization
- Redis tests now gracefully skip (instead of erroring) when Redis server is unavailable

## License

MIT

## Contributing

Pull requests welcome! Please ensure:
- Tests pass (`vendor/bin/phpunit`)
- Code follows PSR-12 standards
- New features include tests and documentation
