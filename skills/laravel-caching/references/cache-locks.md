## Atomic Locks

`Cache::lock()` acquires a mutual exclusion lock backed by the cache store. Use it to prevent concurrent execution of expensive, non-idempotent, or single-writer operations. Laravel 12 ships `FileLock` and `ArrayLock` implementations, so the `file` and `array` drivers fully support atomic locking. The `redis` driver is recommended for production multi-server setups because file locks are not shared across servers; file locks work correctly for single-server deployments and in tests. The `memcached` and `database` drivers also support locking.

```php
$lock = Cache::lock(string $name, int $seconds = 0, ?string $owner = null): Lock
```

- `$name` — unique identifier for the lock resource
- `$seconds` — lock TTL; 0 means no automatic expiry (use with caution)
- `$owner` — optional unique token; auto-generated if omitted

---

## `get()` — Non-Blocking Acquire

Acquire the lock and execute a closure immediately. Returns `false` without executing if the lock is already held.

```php
$executed = Cache::lock("reports:generate", 30)->get(function (): void {
    generateReport();
});

if (! $executed) {
    // Another process holds the lock — skip, return stale data, or queue a retry
    return response()->json(['status' => 'generating'], 202);
}
```

Without a closure, `get()` returns a `bool`. Always call `release()` manually in this form:

```php
$lock = Cache::lock("invoices:{$id}:process", 60);

if ($lock->get()) {
    try {
        processInvoice($id);
    } finally {
        $lock->release();
    }
}
```

---

## `block()` — Blocking Acquire

Wait up to `$seconds` for the lock to become available, then execute the closure. Throws `LockTimeoutException` if the lock cannot be acquired within the wait window.

```php
use Illuminate\Contracts\Cache\LockTimeoutException;

try {
    Cache::lock("invoices:{$id}:pdf", 10)->block(5, function () use ($id): void {
        $pdf = generatePdf($id);
        Cache::put("invoices:{$id}:pdf", $pdf, now()->addHours(1));
    });
} catch (LockTimeoutException) {
    // Could not acquire lock within 5 seconds
    throw new \RuntimeException("PDF generation already in progress for invoice {$id}");
}
```

Without a closure, `block()` returns a `Lock` instance after acquiring. Release it manually:

```php
$lock = Cache::lock("invoices:{$id}:pdf", 10);

try {
    $lock->block(5);                         // waits up to 5 seconds

    $pdf = Cache::get("invoices:{$id}:pdf"); // re-check after acquiring
    if ($pdf === null) {
        $pdf = generatePdf($id);
        Cache::put("invoices:{$id}:pdf", $pdf, now()->addHours(1));
    }
} catch (LockTimeoutException) {
    // handle timeout
} finally {
    $lock->release();
}
```

---

## Releasing Locks

Always release locks in a `finally` block to guarantee release even when an exception is thrown. A lock that is never released blocks all other processes until its TTL expires.

```php
$lock = Cache::lock("resource:lock", 60);

try {
    if ($lock->get()) {
        doWork();
    }
} finally {
    $lock->release();   // safe to call even if get() returned false
}
```

`release()` only releases the lock if the current process owns it. If another process has taken the lock after expiry, this call is a no-op. To force-release regardless of ownership, use `forceRelease()`:

```php
// Only use in administrative/maintenance contexts — bypasses ownership check
Cache::lock("resource:lock")->forceRelease();
```

---

## `restoreLock()` — Passing Locks Between Processes

When a lock needs to span a background job or queue worker, serialize the lock owner token and restore it in the receiving process. This pattern lets a web request acquire a lock and hand it to a queued job to release.

```php
// In the controller / service — acquire and hand off
$lock = Cache::lock("invoices:{$invoice->id}:generate", 120);
$lock->block(5);

$owner = $lock->owner();  // unique token string

ProcessInvoice::dispatch($invoice->id, $owner);

// Do NOT release here — the job will release it
```

```php
// In the job — restore and release
public function handle(): void
{
    $lock = Cache::restoreLock("invoices:{$this->invoiceId}:generate", $this->owner);

    try {
        // ... do work ...
        generateInvoicePdf($this->invoiceId);
    } finally {
        $lock->release();
    }
}
```

Never rely on TTL alone to clean up locks passed between processes. Always release explicitly.

---

## Cache Stampede Prevention

The classic stampede scenario: a popular cache key expires; hundreds of requests hit the miss simultaneously, each triggers a DB query, the DB is overloaded.

### Full Pattern with Double-Check

```php
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

function getInvoicePdf(int $id): string
{
    $cacheKey = "invoices:{$id}:pdf";
    $lockKey  = "locks:invoices:{$id}:pdf";

    // Fast path — no lock needed if cached
    $pdf = Cache::get($cacheKey);
    if ($pdf !== null) {
        return $pdf;
    }

    $lock = Cache::lock($lockKey, 10);

    try {
        $lock->block(5);

        // Double-check: another process may have populated the cache while we waited
        $pdf = Cache::get($cacheKey);
        if ($pdf !== null) {
            return $pdf;
        }

        $pdf = generatePdf($id);
        Cache::put($cacheKey, $pdf, now()->addHours(1));

        return $pdf;
    } catch (LockTimeoutException) {
        // Waited 5 seconds and could not acquire — serve stale or fail gracefully
        throw new \RuntimeException("PDF not available, try again shortly.");
    } finally {
        $lock->release();
    }
}
```

The double-check after `block()` is essential. Without it, every queued request regenerates the value serially, which defeats the purpose of the lock.

---

## Distributed Locks Across Multiple Servers

Locks backed by Redis are globally consistent across all web servers sharing the same Redis instance. No additional configuration is required. Ensure all application servers point to the same `REDIS_HOST`/`REDIS_PORT` and use the same `CACHE_PREFIX` so lock keys are identical cluster-wide.

```ini
# Consistent across all servers
CACHE_STORE=redis
REDIS_HOST=redis.internal
REDIS_PORT=6379
CACHE_PREFIX=myapp_production
```

### Lock Key Naming

Prefix lock keys differently from data keys to make them distinguishable in Redis monitoring tools:

```
locks:invoices:{id}:pdf       ← lock
invoices:{id}:pdf             ← data
```

### Long-Running Operations

For operations that may exceed the lock TTL, extend the lock inside the operation or set a conservative TTL that is larger than the maximum observed execution time. Do not set TTL to 0 (no expiry) in production — it prevents recovery if the process crashes before releasing.

```php
// Set a safe upper bound — 2× the expected maximum runtime
$lock = Cache::lock("jobs:monthly-report", 300); // 5 minutes
```

---

## When Not to Use Locks

- Do not use cache locks as a substitute for database-level transactions. Use DB transactions for atomicity guarantees on database writes.
- Do not use locks for simple read caching — use `Cache::remember()`. Locks are for exclusive write access, not for read deduplication.
- Do not acquire a lock inside a long-running queue job without a release strategy — a crashed worker leaves the lock held until TTL.
