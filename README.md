
# Workflow Orchestrator

A lightweight, stateless workflow orchestration library for PHP 8.2+.

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
- ✅ **Async step processing** - Heavy operations run in background
- ✅ **Header/metadata support** - Pass context between steps
- ✅ **Middleware support** - Add cross-cutting concerns
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

- PHP 8.2+
- PSR-11 container (optional, includes simple container)

## License

MIT

## Contributing

Pull requests welcome! Please ensure:
- Tests pass (`vendor/bin/phpunit`)
- Code follows PSR-12 standards
- New features include tests and documentation
