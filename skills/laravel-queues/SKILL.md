---
name: laravel-queues
description: This skill should be used when the user asks to "create a job", "dispatch a job", "add a queue", "create an event", "create a listener", "schedule a task", "set up broadcasting", "make a job unique", or when working with queues, events, listeners, or scheduled commands in Laravel 12.
version: 1.0.0
---

## Jobs

### Core Requirements

Always implement `ShouldQueue` on every job class intended for async processing. Always use `SerializesModels` to enable automatic model re-fetching when the job runs. Add `InteractsWithQueue` when manual `release()`, `fail()`, or `attempts()` calls are needed inside `handle()`.

```bash
php artisan make:job ProcessInvoice
```

Always set `$tries`, `$backoff`, and `$timeout` explicitly on every job class. Never rely on queue driver defaults — they vary by environment and lead to silent differences in production.

```php
public int $tries = 3;
public array $backoff = [1, 5, 10];
public int $timeout = 60;
```

Always implement `failed(Throwable $e): void` on every job. Use it to mark records as failed, send alerts, or roll back side effects that occurred before the job exhausted its retries.

### Idempotency

Jobs MUST be idempotent. Running the same job twice must produce the same result. Check whether the work has already been completed before performing any mutation:

```php
public function handle(): void
{
    $invoice = Invoice::findOrFail($this->invoiceId);

    if ($invoice->status === InvoiceStatus::Processed) {
        return; // already done — safe to exit
    }

    // ... process
}
```

### Constructor: Store IDs, Not Instances

Store model IDs in job constructors, not model instances. While `SerializesModels` re-fetches the model when the job runs, passing a full model instance causes serialization bloat in the queue payload and may carry stale attribute data if the model changed between dispatch and execution.

```php
// Correct
public function __construct(public readonly int $invoiceId) {}

// Wrong — serializes the full model into the queue payload
public function __construct(public readonly Invoice $invoice) {}
```

### Dispatching Inside Transactions

Use `$afterCommit = true` on the job class (or chain `->afterCommit()` on the dispatch call) when dispatching from inside a database transaction. Without it, the queue worker may pick up and process the job before the transaction commits, causing the job to operate on data that does not yet exist.

```php
public bool $afterCommit = true;
```

### Unique Jobs

Use `ShouldBeUnique` on jobs that must not run concurrently for the same resource. Implement `uniqueId()` to scope the lock to the specific record.

Use `ShouldBeUniqueUntilProcessing` instead when the next dispatch should be allowed to queue while the current job is actively processing — the lock releases at the start of execution rather than at completion.

---

## Events and Listeners

### When to Use Events

Use events for cross-domain communication where multiple independent listeners must react to the same occurrence. `OrderService` fires `OrderPlaced`; `NotificationService` and `AnalyticsService` each listen independently. Neither service knows about the other.

Use direct service or action calls for same-domain logic where execution order is critical, a return value is required, or failure in one step must halt the others.

Do not wrap everything in events. Events decouple, but they also obscure control flow. Prefer observers over events for model lifecycle hooks (created, updated, deleted).

### Async Listeners

Implement `ShouldQueue` on the listener class, not on the event, to make a listener async. Set `$connection`, `$queue`, and `$delay` properties on the listener to control routing and timing.

Use `ShouldHandleEventsAfterCommit` on a queued listener to guarantee its job is not dispatched until the surrounding database transaction commits.

### Auto-Discovery (Laravel 11+)

Events and listeners are auto-discovered by type-hint — no `$listen` array in `EventServiceProvider` is required. Use the `#[AsListener]` attribute on the listener class as an explicit alternative when auto-discovery is disabled or when the listener handles an event from a vendor package.

---

## Scheduled Tasks

### Define in routes/console.php

Define all scheduled tasks in `routes/console.php` using `Schedule::`. The `app/Console/Kernel.php` file was removed in Laravel 11 and does not exist in Laravel 12.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('invoices:send-reminders')->dailyAt('08:00');
Schedule::job(new GenerateReports)->weekly();
```

### Overlap and Multi-Server Guards

Always chain `->withoutOverlapping()` on any task whose execution time may exceed its scheduling interval. Without it, multiple instances of the same task can run concurrently.

Always chain `->onOneServer()` on deployments that run the scheduler on more than one server. Without it, every server executes the task independently at the scheduled time.

```php
Schedule::command('reports:generate')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

---

## Broadcasting

Implement `ShouldBroadcast` on an event class to push it to a WebSocket channel. Implement `broadcastOn()` to return the channel(s), `broadcastAs()` to set the client-side event name, and `broadcastWith()` to control the payload.

Use `ShouldBroadcastNow` to broadcast synchronously (bypasses the queue) during testing or when latency must be minimized.

---

## Artisan Commands Reference

```bash
# Generate
php artisan make:job ProcessInvoice
php artisan make:event OrderPlaced
php artisan make:listener SendOrderConfirmation --event=OrderPlaced
php artisan make:command SendInvoiceReminders

# Queue management
php artisan queue:work redis --queue=invoices,default
php artisan queue:failed
php artisan queue:retry all
php artisan queue:forget <uuid>
php artisan queue:flush
```

---

## Additional Resources

- `references/jobs.md` — Complete job class anatomy, retry configuration, uniqueness strategies, failure handling, all dispatch patterns, chain and batch usage, and testing with `Queue::fake()`.
- `references/events-listeners.md` — Event and listener class anatomy, broadcasting, async listeners, auto-discovery and `#[AsListener]`, event subscribers, when to use events vs direct calls, and testing with `Event::fake()`.
