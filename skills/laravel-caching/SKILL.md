---
name: laravel-caching
description: This skill should be used when the user asks to "cache data", "add caching", "use Redis cache", "cache a query result", "invalidate cache", "set up cache tags", "prevent cache stampede", "use Cache::remember", "configure cache driver", or when working with caching, Redis, cache keys, cache locks, or cache invalidation in Laravel.
version: 1.0.0
---

## Cache Drivers

Configure the active driver in `config/cache.php` via the `CACHE_STORE` environment variable.

| Driver     | When to use                                              |
|------------|----------------------------------------------------------|
| `file`     | Local development only. Never use in production.        |
| `redis`    | Production default. Supports tags, locks, atomic ops.   |
| `database` | When Redis is unavailable but a DB is already present.  |
| `array`    | Automated tests only. Resets between requests.          |

Always set `CACHE_STORE=redis` in production `.env`. Never ship `file` or `array` as the production driver.

```ini
# .env
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

---

## Core Operations

Always prefer `Cache::remember()` over a manual `get()`/`put()` pair. It is atomic for the request and eliminates the pattern of forgetting to call `put()`.

```php
// Preferred — fetches from cache, or runs the closure and stores the result
$invoice = Cache::remember("invoices:{$id}", now()->addMinutes(30), function () use ($id): Invoice {
    return Invoice::findOrFail($id);
});

// Never write this — error-prone and verbose
$invoice = Cache::get("invoices:{$id}");
if ($invoice === null) {
    $invoice = Invoice::findOrFail($id);
    Cache::put("invoices:{$id}", $invoice, now()->addMinutes(30));
}
```

### Method Reference

```php
// Store with explicit TTL (Carbon or seconds)
Cache::put('key', $value, now()->addHours(2));

// Retrieve with a default fallback
$value = Cache::get('key', 'default');

// Remove a single entry
Cache::forget('key');

// Remove every entry in the store (use with caution in shared environments)
Cache::flush();

// Store without expiry — use sparingly (see TTL discipline below)
Cache::rememberForever('key', fn () => expensiveComputation());

// Check existence
if (Cache::has('key')) { ... }

// Retrieve and delete atomically
$value = Cache::pull('key');
```

---

## TTL Discipline

Always pass an explicit TTL to every `put()` and `remember()` call. Never rely on driver defaults.

Use `now()->addMinutes(N)` or other `Carbon` helpers instead of raw integers. Carbon makes the intent readable and avoids confusion between seconds and minutes.

```php
// Clear — obviously 15 minutes
Cache::remember($key, now()->addMinutes(15), fn () => $data);

// Ambiguous — is 900 seconds or 900 minutes?
Cache::remember($key, 900, fn () => $data);
```

Never use `rememberForever()` for:
- User-generated content
- Records that can be updated or deleted
- Anything that changes on a business cycle

Use `rememberForever()` only for truly static data: compiled config trees, country/currency lists, feature flags that require a deploy to change.

---

## Cache Keys

Use namespaced, colon-separated keys. Never use arbitrary strings or interpolate variables without a namespace prefix.

```php
// Correct — namespaced and predictable
"invoices:{$invoiceId}:pdf"
"users:{$userId}:permissions"
"reports:monthly:{$year}:{$month}"

// Wrong — flat keys collide across domains
"invoice_pdf_{$invoiceId}"
"user{$userId}"
```

Define key patterns as constants or methods on a dedicated class, not as inline strings scattered across the codebase:

```php
final class CacheKeys
{
    public static function invoicePdf(int $id): string
    {
        return "invoices:{$id}:pdf";
    }

    public static function userPermissions(int $userId): string
    {
        return "users:{$userId}:permissions";
    }
}
```

---

## Cache Tags

Cache tags group related entries so they can be invalidated together with a single call. Tags are only available with the `redis` and `memcached` drivers. Never use tags with the `file` or `database` driver — it throws a `BadMethodCallException`.

```php
// Store with tags
Cache::tags(['invoices', "invoice:{$id}"])->remember(
    "invoices:{$id}:pdf",
    now()->addHours(1),
    fn () => generatePdf($id)
);

// Invalidate all entries tagged with 'invoices'
Cache::tags(['invoices'])->flush();

// Invalidate a single invoice's entries across all its cached forms
Cache::tags(["invoice:{$id}"])->flush();
```

Always guard tag usage with a driver check or keep it inside a service that is only instantiated when Redis is configured. Document which tags exist and what they group.

---

## Cache Stampede Prevention

A cache stampede occurs when many concurrent requests all find the same expired key and simultaneously run the expensive regeneration, overloading the database. Use `Cache::lock()` to serialize regeneration.

```php
use Illuminate\Support\Facades\Cache;

$lock = Cache::lock("locks:invoices:{$id}:pdf", 10);

try {
    // block() waits up to 5 seconds for the lock; throws LockTimeoutException on timeout
    $lock->block(5);

    // Re-check the cache after acquiring the lock — another process may have populated it
    $pdf = Cache::get("invoices:{$id}:pdf");

    if ($pdf === null) {
        $pdf = generatePdf($id);
        Cache::put("invoices:{$id}:pdf", $pdf, now()->addHours(1));
    }

    return $pdf;
} finally {
    $lock->release();
}
```

Use `get()` instead of `block()` when you want to skip generation rather than wait:

```php
$lock = Cache::lock("locks:report:generate", 30);

if ($lock->get()) {
    try {
        generateReport();
    } finally {
        $lock->release();
    }
} else {
    // Another process is already generating — return stale data or a 202 Accepted
}
```

---

## Where to Cache

Cache in the **service layer**, never in controllers or Eloquent models.

```php
// Correct — caching is an infrastructure concern of the service
final class InvoiceService
{
    public function getPdf(int $id): string
    {
        return Cache::remember(
            CacheKeys::invoicePdf($id),
            now()->addHours(1),
            fn () => $this->generator->generate($id)
        );
    }
}

// Wrong — controller decides cache duration and key format
public function show(int $id): Response
{
    $pdf = Cache::remember("invoices:{$id}:pdf", 3600, fn () => generatePdf($id));
    return response($pdf);
}
```

Pass cache key patterns or TTL constants through the service constructor, not as magic values inline. This makes the values testable and configurable per environment.

---

## Cache Invalidation

Prefer **explicit invalidation** over relying on TTL expiry for critical data. When you mutate data, forget the corresponding cache entry in the same operation.

```php
DB::transaction(function () use ($invoice): void {
    $invoice->update(['status' => 'paid']);

    // Invalidate immediately after mutation — not on next TTL expiry
    Cache::forget(CacheKeys::invoicePdf($invoice->id));
});
```

When a model can affect many cached keys, use tags for bulk invalidation:

```php
public function updateInvoice(Invoice $invoice, array $data): void
{
    $invoice->update($data);

    // Flush all cached representations of this invoice at once
    Cache::tags(["invoice:{$invoice->id}"])->flush();
}
```

Never assume TTL expiry is "close enough" for records that drive financial, security, or user-visible state.

---

## Artisan Commands Reference

```bash
# Clear all cache entries in the configured store
php artisan cache:clear

# Clear a specific tagged group (requires Redis)
# No built-in artisan command — call Cache::tags(['tag'])->flush() in a command or tinker
php artisan tinker
> Cache::tags(['invoices'])->flush();

# Warm the config and route caches (not application cache)
php artisan config:cache
php artisan route:cache
```

---

## Additional Resources

- `references/cache-patterns.md` — Complete `Cache::` method signatures, TTL patterns, tagging with flush, testing with `Cache::fake()` and `Cache::spy()`, and common pitfalls.
- `references/cache-locks.md` — Atomic locks with `Cache::lock()`, `get()` vs `block()`, releasing locks safely, `restoreLock()` for cross-process handoff, and distributed lock patterns.
- `references/cache-drivers.md` — Per-driver configuration details for `file`, `redis`, `database`, and `array`; Predis vs phpredis; production Redis setup; environment variable checklist.
