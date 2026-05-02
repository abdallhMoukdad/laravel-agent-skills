## Driver Overview

Laravel selects the cache driver via the `CACHE_STORE` environment variable, which maps to the `default` key in `config/cache.php`. Each driver has a `stores` entry with its own connection parameters.

```php
// config/cache.php
'default' => env('CACHE_STORE', 'database'),

'stores' => [
    'file'      => [...],
    'redis'     => [...],
    'database'  => [...],
    'array'     => [...],
],
```

---

## `file` Driver

Stores each cache entry as a serialized file in the `storage/framework/cache/data` directory.

```php
'file' => [
    'driver' => 'file',
    'path'   => storage_path('framework/cache/data'),
    'lock_path' => storage_path('framework/cache/data'),
],
```

**Use for:** Local development only when Redis is not available.

**Never use in production because:**
- No support for cache tags (throws `BadMethodCallException`)
- No support for atomic locks
- Performance degrades with large numbers of entries (filesystem I/O)
- Not shared across multiple web servers — each server has its own file cache

---

## `redis` Driver

Backed by a Redis server. The recommended driver for all production applications.

```php
'redis' => [
    'driver'     => 'redis',
    'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
    'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
],
```

The `connection` name maps to an entry in `config/database.php` under `redis`:

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),

    'default' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port'     => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
    ],

    'cache' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port'     => env('REDIS_PORT', 6379),
        'database' => env('REDIS_CACHE_DB', 1),   // separate DB from session/queue
    ],
],
```

Always use a **separate Redis database** (different integer) for cache, sessions, and queues. This allows `Cache::flush()` to be called without wiping session or queue data.

### phpredis (Recommended)

phpredis is a C extension and is faster than Predis. It is the default client as of Laravel 10+.

```bash
# Install the extension
pecl install redis

# Verify
php -m | grep redis
```

```ini
# .env
REDIS_CLIENT=phpredis
```

### Predis (Pure PHP Fallback)

Use Predis when the phpredis extension cannot be installed (e.g., restricted shared hosting).

```bash
composer require predis/predis
```

```ini
# .env
REDIS_CLIENT=predis
```

Predis supports the same API but has higher CPU overhead per request. Do not mix clients within the same application — pick one and be consistent.

### Redis Cluster

For Redis Cluster deployments, define the cluster in `config/database.php` and set `options.cluster = 'redis'`:

```php
'redis' => [
    'client' => 'phpredis',
    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix'  => env('CACHE_PREFIX', 'myapp'),
    ],
    'clusters' => [
        'default' => [
            ['host' => env('REDIS_HOST'), 'port' => 6379, 'database' => 0],
        ],
    ],
],
```

Cache tags use hash-tagging internally — ensure your cluster is configured to keep tagged keys on the same shard.

---

## `database` Driver

Stores cache entries as rows in a database table.

```php
'database' => [
    'driver'     => 'database',
    'connection' => env('DB_CACHE_CONNECTION'),   // null = default connection
    'table'      => env('DB_CACHE_TABLE', 'cache'),
    'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
    'lock_table' => env('DB_CACHE_LOCK_TABLE'),
],
```

### Create the Cache Table

```bash
php artisan make:cache-table
php artisan migrate
```

**Use for:**
- Applications that cannot add Redis as a dependency
- Low-traffic applications where a separate cache service is unnecessary
- Environments where the DB and app are co-located on the same server

**Limitations:**
- Does not support cache tags
- Higher latency than Redis (row reads and writes vs in-memory ops)
- At high traffic, cache reads/writes compete with application queries for DB connections
- `Cache::flush()` truncates the entire table

---

## `array` Driver

Stores cache entries in a PHP array in memory. Entries do not persist between requests or test cases.

```php
'array' => [
    'driver'    => 'array',
    'serialize' => false,
],
```

**Use for:** Automated tests only.

The `array` driver supports tags and makes assertions against `Cache::fake()` work correctly. Never use it in production — every request starts with an empty cache.

In tests, use `Cache::fake()` (which uses the array driver internally) rather than setting `CACHE_STORE=array` globally:

```php
beforeEach(function (): void {
    Cache::fake();
});
```

---

## Production Redis Setup Checklist

Follow this checklist when deploying a Laravel application with Redis caching:

```ini
# Required
CACHE_STORE=redis
REDIS_HOST=<redis-hostname-or-ip>
REDIS_PORT=6379
REDIS_PASSWORD=<strong-password>
REDIS_CLIENT=phpredis               # or predis

# Strongly recommended
REDIS_CACHE_DB=1                    # separate DB index from session (0) and queue (2)
CACHE_PREFIX=myapp_production       # namespaces keys — prevents collision across environments

# Optional for TLS (Redis 6+ with TLS enabled)
REDIS_SCHEME=tls
```

### Verify Redis Connectivity

```bash
php artisan tinker
> Cache::put('health', 'ok', 10);
> Cache::get('health');  // should return 'ok'
```

### Key Prefix

The `CACHE_PREFIX` env var is prepended to every cache key Laravel writes. Without a prefix, staging and production environments sharing the same Redis instance will overwrite each other's keys.

```php
// config/cache.php
'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_cache'),
```

---

## Driver Comparison Summary

| Feature              | `file`  | `redis`  | `database` | `array`     |
|----------------------|---------|----------|------------|-------------|
| Cache tags           | No      | Yes      | No         | Yes (fake)  |
| Atomic locks         | No      | Yes      | Yes        | No          |
| Multi-server shared  | No      | Yes      | Yes        | No          |
| TTL precision        | Seconds | Seconds  | Seconds    | Seconds     |
| Production use       | No      | Yes      | Yes (limited) | No       |
| Test use             | Avoid   | Avoid    | Avoid      | Yes         |
