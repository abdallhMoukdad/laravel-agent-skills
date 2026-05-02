## Event Class

In Laravel 12 an event is a plain PHP class. No interface is required. Use `public readonly` constructor properties for clean, immutable event data:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;

final class OrderPlaced
{
    public function __construct(
        public readonly Order $order,
        public readonly string $placedAt,
    ) {}
}
```

Fire the event anywhere in application code:

```php
use App\Events\OrderPlaced;

event(new OrderPlaced($order, now()->toISOString()));

// Or via the static helper:
OrderPlaced::dispatch($order, now()->toISOString());
```

### Broadcasting (Real-Time)

Implement `ShouldBroadcast` on the event class to push it to a WebSocket channel. Implement `ShouldBroadcastNow` to bypass the queue and broadcast synchronously:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

final class OrderStatusUpdated implements ShouldBroadcast
{
    public function __construct(public readonly Order $order) {}

    /** @return Channel|Channel[] */
    public function broadcastOn(): Channel|array
    {
        return new PrivateChannel("orders.{$this->order->id}");
    }

    /** Client-side event name */
    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    /** Payload sent to the client */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'status'   => $this->order->status,
        ];
    }
}
```

---

## Listener Class

Type-hint the event in `handle()` — Laravel resolves and injects it automatically:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Notifications\OrderConfirmation;

final class SendOrderConfirmation
{
    public function handle(OrderPlaced $event): void
    {
        $event->order->user->notify(new OrderConfirmation($event->order));
    }
}
```

### Async Listener

Implement `ShouldQueue` on the listener (not the event) to process it asynchronously via the queue:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

final class RecordOrderAnalytics implements ShouldQueue
{
    // Route to a specific connection and queue
    public string $connection = 'redis';
    public string $queue      = 'analytics';

    // Delay dispatch by 10 seconds
    public int $delay = 10;

    public function handle(OrderPlaced $event): void
    {
        // ... record analytics
    }

    public function failed(OrderPlaced $event, Throwable $e): void
    {
        // Cleanup on exhausted retries
        Log::error('Analytics listener failed', ['order' => $event->order->id]);
    }
}
```

### After-Commit Guarantee for Queued Listeners

Use `ShouldHandleEventsAfterCommit` on a queued listener to ensure its job is not dispatched until the surrounding database transaction commits:

```php
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SyncOrderToWarehouse implements ShouldQueue, ShouldHandleEventsAfterCommit
{
    public function handle(OrderPlaced $event): void
    {
        // Runs only after the transaction that fired the event has committed
    }
}
```

---

## Auto-Discovery (Laravel 11+)

Events and listeners are auto-discovered by type-hint in `handle()`. No `$listen` array in `EventServiceProvider` is required.

Use the `#[AsListener]` attribute as an explicit alternative, particularly useful when auto-discovery is disabled or when listening to events from vendor packages:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Queue\Attributes\AsListener;

#[AsListener(OrderPlaced::class)]
final class NotifyWarehouse
{
    public function handle(OrderPlaced $event): void
    {
        // ...
    }
}
```

### Stopping Propagation

Return `false` from a listener's `handle()` method to prevent subsequent listeners from receiving the event:

```php
public function handle(OrderPlaced $event): false|void
{
    if ($event->order->isFraudulent()) {
        $event->order->flag();
        return false; // no further listeners run
    }
}
```

---

## Event Subscribers

An event subscriber is a single class that handles multiple events. Implement `subscribe()` returning an array mapping event classes to handler methods:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Events\OrderCancelled;
use App\Events\OrderRefunded;
use Illuminate\Events\Dispatcher;

final class OrderEventSubscriber
{
    public function handleOrderPlaced(OrderPlaced $event): void
    {
        // ...
    }

    public function handleOrderCancelled(OrderCancelled $event): void
    {
        // ...
    }

    public function handleOrderRefunded(OrderRefunded $event): void
    {
        // ...
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderPlaced::class    => 'handleOrderPlaced',
            OrderCancelled::class => 'handleOrderCancelled',
            OrderRefunded::class  => 'handleOrderRefunded',
        ];
    }
}
```

Register the subscriber in a service provider:

```php
Event::subscribe(OrderEventSubscriber::class);
```

---

## Events vs Direct Calls — Decision Guide

| Scenario | Approach |
|---|---|
| Multiple services react to one occurrence | Event |
| Execution order is critical | Direct call |
| Return value is needed | Direct call |
| Same bounded domain, sequential steps | Direct call |
| Decoupling across domains (notifications, analytics, auditing) | Event |
| Model lifecycle (created, updated, deleted) | Observer |

### Good Event Use

```
OrderService::place()
  └─ fires OrderPlaced
       ├─ NotificationService (listener) — sends confirmation email
       ├─ AnalyticsService (listener) — records conversion event
       └─ AuditService (listener) — writes audit log
```

`OrderService` knows nothing about `NotificationService` or `AnalyticsService`. Adding a new listener requires zero changes to `OrderService`.

### Bad Event Use — Over-Eventing

```php
// Anti-pattern: firing an event just to run one thing sequentially
event(new InvoiceCreated($invoice));
// ... immediately followed by a listener that is the only handler
// and must run before the HTTP response is returned
```

For model lifecycle reactions, prefer Eloquent observers:

```bash
php artisan make:observer InvoiceObserver --model=Invoice
```

---

## Testing

```php
use App\Events\OrderPlaced;
use App\Listeners\SendOrderConfirmation;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake();
});

it('dispatches OrderPlaced when an order is placed', function (): void {
    $order = Order::factory()->create();

    placeOrder($order);

    // Assert the event was dispatched
    Event::assertDispatched(OrderPlaced::class);

    // Assert with a payload check
    Event::assertDispatched(
        OrderPlaced::class,
        fn (OrderPlaced $e): bool => $e->order->id === $order->id,
    );

    // Assert the listener is registered for the event
    Event::assertListening(OrderPlaced::class, SendOrderConfirmation::class);

    // Assert it was dispatched exactly once
    Event::assertDispatchedTimes(OrderPlaced::class, 1);
});

it('does not dispatch events for draft orders', function (): void {
    $order = Order::factory()->draft()->create();

    saveDraftOrder($order);

    Event::assertNothingDispatched();
});

it('still dispatches real events for non-faked classes', function (): void {
    // Partial fake — only OrderPlaced is intercepted; all other events fire normally
    Event::fake([OrderPlaced::class]);

    $order = Order::factory()->create();
    placeOrder($order);

    Event::assertDispatched(OrderPlaced::class);
    // Other events (e.g. OrderSaved) fired for real and triggered their listeners
});
```
