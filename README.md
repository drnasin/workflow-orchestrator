# Workflow Orchestrator

A lightweight, stateless workflow orchestration library for PHP 8.1+.

## Installation

```bash
composer require drnasin/workflow-orchestrator
```

## Quick Start

```php
use WorkflowOrchestrator\WorkflowOrchestrator;
use WorkflowOrchestrator\Attributes\Orchestrator;
use WorkflowOrchestrator\Attributes\Handler;

// Define your workflow
class OrderProcessor
{
    #[Orchestrator(channel: 'process.order')]
    public function processOrder(Order $order): array
    {
        $steps = ['validate', 'payment'];
        
        if ($order->isPremium()) {
            $steps[] = 'apply-discount';
        }
        
        $steps[] = 'confirmation';
        return $steps;
    }

    #[Handler(channel: 'validate')]
    public function validate(Order $order): Order
    {
        // Validation logic
        return $order;
    }

    #[Handler(channel: 'payment')]
    public function processPayment(Order $order): Order
    {
        // Payment logic
        return $order;
    }

    #[Handler(channel: 'apply-discount')]
    public function applyDiscount(Order $order): Order
    {
        // Discount logic
        return $order;
    }

    #[Handler(channel: 'confirmation')]
    public function sendConfirmation(Order $order): Order
    {
        // Send confirmation
        return $order;
    }
}

// Execute workflow
$orchestrator = WorkflowOrchestrator::create()
    ->register(OrderProcessor::class);

$result = $orchestrator->execute('process.order', $order);
```

## Features

- ✅ Stateless workflow execution
- ✅ Dynamic step routing
- ✅ Async step processing
- ✅ Header/metadata support
- ✅ Middleware support
- ✅ Clean PHP 8+ API
- ✅ Container integration
- ✅ Zero dependencies (except PSR)

## Advanced Usage

### Async Steps
```php
#[Handler(channel: 'heavy-processing', async: true)]
public function processHeavyTask(Data $data): Data
{
    // This will be queued for async processing
    return $data;
}
```

### Headers
```php
#[Handler(channel: 'enrich', returnsHeaders: true)]
public function enrichData(Order $order): array
{
    return ['customer_tier' => $order->getCustomer()->getTier()];
}

#[Handler(channel: 'use-header')]
public function useHeader(Order $order, #[Header('customer_tier')] string $tier): Order
{
    // Use the header value
    return $order;
}
```

## License

MIT