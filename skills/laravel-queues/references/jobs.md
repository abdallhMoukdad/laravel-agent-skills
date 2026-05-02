## Job Class Anatomy

A complete job class with all relevant traits:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessInvoice implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Store the invoice ID, not the Invoice model instance.
     * SerializesModels fetches a fresh instance when the job runs.
     * Storing the full model bloats the queue payload and risks stale data.
     */
    public function __construct(public readonly int $invoiceId) {}

    // Retry and timeout configuration
    public int $tries       = 3;
    public array $backoff   = [1, 5, 10]; // seconds between retry attempts
    public int $maxExceptions = 2;
    public int $timeout     = 60;

    // Dispatch only after the enclosing DB transaction commits
    public bool $afterCommit = true;

    public function handle(): void
    {
        $invoice = Invoice::findOrFail($this->invoiceId);

        // Idempotency guard — safe to re-run
        if ($invoice->status === 'processed') {
            return;
        }

        // ... business logic
        $invoice->markAsProcessed();
    }

    public function failed(Throwable $e): void
    {
        // Cleanup on exhausted retries: notify, mark failed, rollback side effects
        $invoice = Invoice::find($this->invoiceId);
        $invoice?->markAsFailed($e->getMessage());

        // Optionally notify via Slack/email
        // Notification::route('mail', 'ops@example.com')->notify(new JobFailedNotification($e));
    }

    // Unique lock scoped to the invoice ID
    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }
}
```

### When `SerializesModels` Is Appropriate With a Model Instance

Storing a model instance (not just an ID) is acceptable when:
- The model is small, has no lazy-loaded relations, and will not change between dispatch and execution.
- You want `$this->invoice` auto-refreshed by `SerializesModels` without an explicit `findOrFail`.

Trade-off: the full serialized model (including all attributes at dispatch time) is stored in the queue payload. For models with many columns or binary fields, this is wasteful. On high-throughput queues the payload size matters.

```php
// Acceptable for small, stable models — SerializesModels re-fetches it
public function __construct(public readonly Invoice $invoice) {}
```

---

## Retry Configuration

```php
// Maximum number of attempts (including the first)
public int $tries = 3;

// Exponential backoff in seconds: 1s after attempt 1, 5s after attempt 2, 10s after attempt 3
public array $backoff = [1, 5, 10];

// Fail the job after this many unhandled exceptions, even if $tries is not exhausted
public int $maxExceptions = 2;

// Kill the worker process if handle() runs longer than this (seconds)
public int $timeout = 60;
```

### Time-Based Expiry Instead of Attempt Count

Use `retryUntil()` when the job should keep retrying for a fixed window regardless of attempt count:

```php
use DateTime;

public function retryUntil(): DateTime
{
    // Keep retrying for 10 minutes from the time the job was originally dispatched
    return now()->addMinutes(10);
}
```

---

## Uniqueness

### `ShouldBeUnique`

The job will not be dispatched if a job with the same `uniqueId()` is already in the queue or processing. The lock is held until the job completes (or fails).

```php
use Illuminate\Contracts\Queue\ShouldBeUnique;

final class SendInvoice implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $invoiceId) {}

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    /** Lock TTL in seconds — prevents stale locks if the worker dies */
    public int $uniqueFor = 3600;
}
```

### `ShouldBeUniqueUntilProcessing`

The lock is released when the job **starts** processing (not when it completes). Use this when you want subsequent dispatches to queue while the current job is actively running:

```php
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

final class SendInvoice implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $invoiceId) {}

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }
}
```

---

## Failure Handling

### `failed()` Callback

Always implement `failed()` for cleanup when the job exhausts all retries:

```php
public function failed(Throwable $e): void
{
    Invoice::find($this->invoiceId)?->markAsFailed($e->getMessage());
    // Notify the ops team, revert external API calls, etc.
}
```

### Manual Failure and Re-Queue Inside `handle()`

```php
public function handle(): void
{
    try {
        // ... work
    } catch (TransientApiException $e) {
        // Re-queue with a 30-second delay — counts as one attempt
        $this->release(30);
        return;
    } catch (PermanentFailureException $e) {
        // Mark as permanently failed without waiting for $tries to exhaust
        $this->fail($e);
        return;
    }
}
```

### Failed Job CLI Commands

```bash
# List all failed jobs
php artisan queue:failed

# Retry a single failed job by UUID
php artisan queue:retry 5e3b1e2a-...

# Retry all failed jobs
php artisan queue:retry all

# Delete a specific failed job
php artisan queue:forget 5e3b1e2a-...

# Delete all failed jobs
php artisan queue:flush
```

---

## Dispatching Patterns

### Standard Dispatch

```php
ProcessInvoice::dispatch($invoice->id);
```

### Conditional Dispatch

```php
ProcessInvoice::dispatchIf($invoice->isReady(), $invoice->id);
ProcessInvoice::dispatchUnless($invoice->isCancelled(), $invoice->id);
```

### After HTTP Response

```php
// Job dispatched after the response is sent to the client
ProcessInvoice::dispatchAfterResponse($invoice->id);
```

### Queue and Connection Routing

```php
ProcessInvoice::dispatch($invoice->id)
    ->onQueue('invoices')
    ->onConnection('redis');
```

### After-Commit Guarantee at the Call Site

```php
// Either set $afterCommit = true on the class (preferred),
// or chain afterCommit() on the dispatch call:
ProcessInvoice::dispatch($invoice->id)->afterCommit();
```

### Delayed Dispatch

```php
ProcessInvoice::dispatch($invoice->id)->delay(now()->addMinutes(5));
```

### Sequential Chain

All jobs in a chain share the same connection and queue. If one fails, the rest are not executed:

```php
use Illuminate\Support\Facades\Bus;

Bus::chain([
    new ValidateInvoice($invoice->id),
    new ProcessInvoice($invoice->id),
    new SendInvoiceEmail($invoice->id),
])->onQueue('invoices')->dispatch();
```

### Parallel Batch

Jobs run concurrently. Use `then`, `catch`, and `finally` callbacks to react to batch completion:

```php
use Illuminate\Support\Facades\Bus;
use Illuminate\Bus\Batch;
use Throwable;

$batch = Bus::batch([
    new ProcessInvoice($invoiceIds[0]),
    new ProcessInvoice($invoiceIds[1]),
    new ProcessInvoice($invoiceIds[2]),
])
->then(function (Batch $batch): void {
    // All jobs completed successfully
    GenerateBatchReport::dispatch($batch->id);
})
->catch(function (Batch $batch, Throwable $e): void {
    // First job failure — batch may still have pending jobs
    Log::error('Batch failed', ['batch' => $batch->id, 'error' => $e->getMessage()]);
})
->finally(function (Batch $batch): void {
    // Batch finished — regardless of success or failure
})
->onQueue('invoices')
->dispatch();
```

---

## Testing

```php
use App\Jobs\ProcessInvoice;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('dispatches ProcessInvoice when invoice is approved', function (): void {
    $invoice = Invoice::factory()->create();

    approveInvoice($invoice);

    // Assert the job was pushed
    Queue::assertPushed(ProcessInvoice::class);

    // Assert it was pushed to the correct queue
    Queue::assertPushedOn('invoices', ProcessInvoice::class);

    // Assert it was pushed with the correct payload
    Queue::assertPushed(ProcessInvoice::class, fn (ProcessInvoice $job): bool
        => $job->invoiceId === $invoice->id
    );

    // Assert nothing else was pushed
    Queue::assertPushedTimes(ProcessInvoice::class, 1);
});

it('does not dispatch when invoice is not ready', function (): void {
    $invoice = Invoice::factory()->draft()->create();

    tryApproveInvoice($invoice);

    Queue::assertNothingPushed();
});
```
