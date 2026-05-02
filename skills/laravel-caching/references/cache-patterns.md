## Cache:: Method Reference

### `Cache::remember()`

Retrieve an item from the cache, or execute a closure and store the result if missing.

```php
Cache::remember(string $key, \DateTimeInterface|\DateInterval|int $ttl, Closure $callback): mixed
```

```php
$user = Cache::remember("users:{$id}", now()->addMinutes(30), function () use ($id): User {
    return User::with('roles')->findOrFail($id);
});
```

### `Cache::rememberForever()`

Same as `remember()` but stores without expiry.

```php
Cache::rememberForever(string $key, Closure $callback): mixed
```

```php
// Appropriate: static reference data that changes only via deploy
$countries = Cache::rememberForever('ref:countries', fn () => Country::all());
```

Use only for data that is truly static or controlled by a deploy cycle. For all other data, always pass a TTL.

### `Cache::put()`

Store an item with an explicit TTL.

```php
Cache::put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
```

```php
Cache::put("invoices:{$id}:summary", $summary, now()->addHours(2));
```

Passing `null` as the TTL stores the item forever — avoid this in application code; use `Cache::forever()` or `rememberForever()` to make the intent explicit.

### `Cache::get()`

Retrieve an item by key, with an optional default.

```php
Cache::get(string $key, mixed $default = null): mixed
```

```php
$value = Cache::get('feature:flags', []);
```

### `Cache::forget()`

Remove a single item from the cache.

```php
Cache::forget(string $key): bool
```

```php
Cache::forget("invoices:{$id}:pdf");
```

### `Cache::flush()`

Remove all items from the active cache store. In production, this affects every key in the store (including sessions if they share the same Redis database). Use targeted tag invalidation instead.

Note: on the Redis driver, `Cache::flush()` issues `FLUSHDB` and ignores `CACHE_PREFIX` — it wipes the entire Redis database index. Use a dedicated `REDIS_CACHE_DB` to isolate cache from sessions/queues.

```php
Cache::flush(): bool
```

### `Cache::has()` / `Cache::missing()`

```php
if (Cache::has("invoices:{$id}")) { ... }
if (Cache::missing("invoices:{$id}")) { ... }
```

### `Cache::pull()`

Retrieve an item and immediately delete it from the cache (atomic).

```php
$token = Cache::pull("password-reset:{$email}");
```

### `Cache::increment()` / `Cache::decrement()`

Atomically increment or decrement a stored integer.

```php
Cache::increment('stats:page-views');
Cache::increment('stats:api-calls', 5);
Cache::decrement('quota:remaining');
```

### `Cache::many()` / `Cache::putMany()`

Retrieve or store multiple keys in a single operation. Reduces round-trips to Redis.

```php
$values = Cache::many(['key1', 'key2', 'key3']);

Cache::putMany([
    "invoices:{$id}:pdf" => $pdf,
    "invoices:{$id}:summary" => $summary,
], now()->addHours(1));
```

---

## TTL Patterns

Always use Carbon helpers for clarity. Never mix units (raw integers are seconds in Laravel, not minutes).

```php
// Preferred Carbon forms
now()->addSeconds(30)
now()->addMinutes(15)
now()->addHours(6)
now()->addDay()
now()->addWeek()

// Pin to an absolute time — cache until midnight
today()->endOfDay()

// DateInterval form — also acceptable
new \DateInterval('PT15M')  // 15 minutes
```

Define TTL constants centrally for consistency across the service:

```php
final class CacheTtl
{
    public const PERMISSIONS = 900;         // 15 min
    public const PDF_DOCUMENT = 3600;       // 1 hour
    public const REFERENCE_DATA = 86400;    // 24 hours
}

Cache::remember($key, CacheTtl::PDF_DOCUMENT, $callback);
```

---

## Cache Tags

Group related keys under one or more tags so they can be invalidated together. Tags require the `redis` or `memcached` driver.

```php
// Write with tags
Cache::tags(['invoices', "invoice:{$id}"])->put(
    "invoices:{$id}:pdf",
    $pdf,
    now()->addHours(1)
);

// Read with tags (must specify the same tags)
$pdf = Cache::tags(['invoices', "invoice:{$id}"])->get("invoices:{$id}:pdf");

// Remember pattern with tags
$report = Cache::tags(['reports', 'monthly'])->remember(
    "reports:monthly:{$year}:{$month}",
    now()->addDay(),
    fn () => buildMonthlyReport($year, $month)
);
```

### Tag Invalidation

```php
// Flush all cached entries tagged 'invoices' — bulk invalidation
Cache::tags(['invoices'])->flush();

// Flush entries for a single invoice across all of its cached forms
Cache::tags(["invoice:{$id}"])->flush();

// Flush a specific entry while preserving other tagged entries
Cache::tags(['invoices'])->forget("invoices:{$id}:pdf");
```

### Tag Pitfall: File Driver

Calling `Cache::tags()` with the `file` or `database` driver throws `BadMethodCallException`. Guard with a driver check or architect so tag usage is isolated to production paths:

```php
// Check driver before using tags
if (Cache::supportsTags()) {
    Cache::tags(['invoices'])->flush();
} else {
    Cache::forget("invoices:{$id}:pdf"); // fallback to targeted forget
}
```

---

## Common Pitfalls

### Forgetting to Invalidate on Mutation

The most common bug: updating a record but not clearing its cached representation.

```php
// Wrong — cache will serve stale data until TTL expires
$invoice->update(['status' => 'paid']);

// Correct — invalidate immediately
$invoice->update(['status' => 'paid']);
Cache::forget(CacheKeys::invoicePdf($invoice->id));
```

### Using `flush()` in Production

`Cache::flush()` clears the entire store. If sessions, rate-limit counters, or other features share the same Redis database, they are wiped too. Always prefer tag-based invalidation or `forget()` for targeted keys.

### Caching `null` Results

`Cache::remember()` will not cache a `null` return value from the closure — it considers it a miss and will call the closure again on the next request. If `null` is a valid cached result, wrap it:

```php
$result = Cache::remember($key, $ttl, function () {
    $value = findOrNull();
    return ['value' => $value]; // wrap so null is preserved
});
$actual = $result['value'];
```

### Shared Cache Keys Across Environments

Always prefix keys with an application or environment identifier when multiple apps or environments share a Redis instance:

```ini
# config/cache.php — set via .env
'prefix' => env('CACHE_PREFIX', 'myapp_production'),
```

---

## Testing

A built-in `Cache` test fake (and assertion methods like `assertStored`, `assertMissing`, `assertHas`, `assertForgotten`) does not exist in Laravel 12. Use one of the two approaches below instead.

### Option A — Array driver (preferred for integration tests)

Set `CACHE_STORE=array` in `phpunit.xml` or `.env.testing`. The array driver is an in-memory store; after the code under test runs, assert directly with `Cache::get()`.

```xml
<!-- phpunit.xml -->
<env name="CACHE_STORE" value="array"/>
```

```php
it('caches the invoice', function () {
    // array driver stores in-memory — no fake needed
    $invoice = Invoice::factory()->create();

    app(InvoiceService::class)->fetchWithCache($invoice->id);

    expect(Cache::get("invoices:{$invoice->id}"))->not->toBeNull();
});
```

### Option B — `Cache::spy()` (for interaction tests)

Use `Cache::spy()` when you need to verify the number of cache reads/writes without replacing the underlying store.

```php
it('calls remember once', function () {
    Cache::spy();

    app(InvoiceService::class)->fetchWithCache(1);

    Cache::shouldHaveReceived('remember')->once();
});
```

---

## Stale-While-Revalidate with `Cache::flexible()`

`Cache::flexible()` is a Laravel 11+/12 method that implements stale-while-revalidate: it serves the cached value immediately and regenerates in the background using `defer()` once the item enters its stale window. It eliminates perceived latency without a lock.

```php
Cache::flexible(string $key, array $ttl, callable $callback, array|null $lock = null, bool $alwaysDefer = false): mixed
```

`$lock = ['seconds' => N, 'owner' => '...']` controls the background-refresh lock used to prevent duplicate computations.

```php
$result = Cache::flexible("invoices:{$id}", [
    now()->addMinutes(5),   // fresh for 5 minutes
    now()->addMinutes(10),  // serve stale for up to 10 minutes, then recompute
], fn () => Invoice::find($id));
```

The first TTL value is the "fresh" window. The second is the "stale" window — during this period the old value is returned immediately and a background job refreshes it. After the stale window expires the key is regenerated synchronously on the next request.

Use `Cache::flexible()` for read-heavy data where a slightly stale response is acceptable (dashboards, aggregates, reports). Use `Cache::remember()` with a lock when stale data must never be served (pricing, inventory).
