## Structured Logging Fundamentals

Structured logging means every log entry is a machine-readable record: a stable message string (used as an event identifier) plus a context object of key-value pairs. Log aggregators (Datadog, Papertrail, Logtail, CloudWatch) index on keys, not on free-form text.

```php
// Unstructured — aggregator must parse the string to extract user_id and order_id
Log::info("User 42 placed order 1001 for $99.00");

// Structured — aggregator indexes user_id, order_id, total as first-class fields
Log::info('order.placed', [
    'user_id'  => 42,
    'order_id' => 1001,
    'total'    => 99.00,
    'currency' => 'USD',
]);
```

Use dot-notation event names for messages (`order.placed`, `payment.failed`, `auth.login_failed`). This creates a stable vocabulary that product and engineering can query, alert on, and chart.

---

## `Log::shareContext()` — Per-Request Context in Middleware

`Log::shareContext()` merges fields into every log entry across **all channels** for the current request lifecycle. Use this in middleware rather than `Log::withContext()`, which only applies to the default channel and will not propagate to named channels like `audit` or `slack`. Call it once; every subsequent `Log::info()`, `Log::error()`, etc., in that request automatically includes those fields.

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class LogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // shareContext() — applies to ALL channels (including audit, slack, etc.)
        // withContext() — applies only to the default channel
        Log::shareContext([
            'request_id' => $request->headers->get('X-Request-Id') ?? Str::uuid()->toString(),
            'user_id'    => $request->user()?->id,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'route'      => $request->route()?->getName(),
        ]);

        return $next($request);
    }
}
```

Register in `bootstrap/app.php` so it runs before any service or controller logs:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->prepend(App\Http\Middleware\LogContext::class);
})
```

The `request_id` field links all log entries from the same HTTP request together, which is essential when searching logs for a specific user-reported issue.

---

## Generating and Propagating Request IDs

Accept the request ID from a header when running behind a load balancer or API gateway that generates IDs upstream. Fall back to generating a local UUID when none is present:

```php
'request_id' => $request->headers->get('X-Request-Id')
    ?? $request->headers->get('X-Correlation-Id')
    ?? Str::uuid()->toString(),
```

Return the request ID in the response so clients can include it in bug reports:

```php
public function handle(Request $request, Closure $next): Response
{
    $requestId = $request->headers->get('X-Request-Id') ?? Str::uuid()->toString();

    // shareContext() — applies to ALL channels (including audit, slack, etc.)
    // withContext() — applies only to the default channel
    Log::shareContext(['request_id' => $requestId]);

    $response = $next($request);
    $response->headers->set('X-Request-Id', $requestId);

    return $response;
}
```

---

## `Log::shareContext()` — Context Across All Channels

`Log::shareContext()` and `Log::withContext()` are both request-scoped — neither persists across requests. The real distinction is which channels they apply to:

- `Log::withContext([...])` — applies to the **default channel only** (current request)
- `Log::shareContext([...])` — applies to **all channels** (current request)

In HTTP both are reset per request. Use `shareContext()` in middleware for fields you want included in every channel (including dedicated `audit`/`slack` channels) for that request.

```php
// In middleware — applies to every channel for this request
Log::shareContext([
    'request_id'  => $requestId,
    'environment' => app()->environment(),
]);
```

For request-to-queue propagation (where context must survive into a worker process), use the `Context` facade — see the next section.

---

## Context Inheritance in Queued Jobs

Context set via `Log::shareContext()` in an HTTP request does not automatically carry over to queued jobs dispatched during that request — unless you use the `Context` facade (see below). The manual pattern is to pass context via constructor and re-establish it inside `handle()`:

> **Prefer the `Context` facade (Laravel 11+)** — see the next section. It propagates automatically without constructor boilerplate.

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

final class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly string $requestId,
    ) {}

    public function handle(): void
    {
        // Re-establish request-scoped context inside the worker process
        Context::add([
            'request_id' => $this->requestId,
            'order_id'   => $this->orderId,
            'job'        => static::class,
        ]);

        Log::info('job.started');

        // ... work

        Log::info('job.completed');
    }
}
```

Dispatch with context:

```php
ProcessOrder::dispatch($order->id, $requestId);
```

---

## The Context Facade (Laravel 11+)

Laravel 11 introduced the `Illuminate\Support\Facades\Context` facade as the preferred way to propagate request context across log entries **and** queued jobs without any constructor boilerplate.

### Setting context in middleware

```php
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

// In middleware — set once, propagates to all log entries AND all dispatched jobs automatically
Context::add('request_id', (string) Str::uuid());
Context::add('user_id', $request->user()?->id);
```

### What `Context::add()` does automatically

- **Appears in all log entries** via the built-in `ContextLogProcessor` — across ALL channels, not just the default channel.
- **Propagates to dispatched queued jobs** — the context is serialized into the job payload and restored on the worker process. No constructor passing required.

### Hidden context

```php
// addHidden() — propagates to queued jobs but does NOT appear in log entries
// Use for sensitive values you need in jobs but must not leak into logs
Context::addHidden('auth_token', $request->bearerToken());
```

### Retrieving context values

```php
Context::get('request_id');   // single key
Context::all();               // all visible context as array
Context::allHidden();         // all hidden context as array
```

### When to use `Context::add()` vs `Log::shareContext()` vs `Log::withContext()`

| | `Context::add()` | `Log::shareContext()` | `Log::withContext()` |
|---|---|---|---|
| Appears in log entries | Yes (all channels) | Yes (all channels) | Default channel only |
| Propagates to queued jobs | Yes, automatically | No | No |
| Scope | Per-request (auto-restored in jobs) | Per-request | Per-request |
| Hidden variant available | Yes (`addHidden`) | No | No |

None of these persist across HTTP requests. For request-to-queue propagation, use `Context::add()`.

**Rule of thumb:** Use `Context::add()` in middleware when context must survive into a queued job. Use `Log::shareContext()` when you only need cross-channel context within the current request. Use `Log::withContext()` when the context is only relevant to the default channel.

---

## Monolog Processors

A Monolog processor is a callable that receives and returns a log record array, allowing you to add, modify, or remove fields on every entry.

Register processors globally via `tap` on a channel (see `channels.md`) or by pushing them in a service provider:

```php
// app/Providers/AppServiceProvider.php

use Monolog\LogRecord;

public function boot(): void
{
    /** @var \Monolog\Logger $monolog */
    $monolog = Log::getLogger();

    // Monolog 3 (Laravel 12): LogRecord is immutable — use ->with() to return a modified copy
    $monolog->pushProcessor(function (LogRecord $record): LogRecord {
        return $record->with(extra: array_merge($record->extra, [
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]));
    });
}
```

Useful processors from `monolog/monolog`:

- `IntrospectionProcessor` — adds `file`, `line`, `class`, `function` to every entry
- `WebProcessor` — adds `url`, `ip`, `http_method`, `server`, `referrer`
- `MemoryUsageProcessor` — adds current and peak memory usage
- `GitProcessor` — adds git branch and commit hash

Install via `monolog/monolog` (already a Laravel dependency):

```php
use Monolog\Processor\IntrospectionProcessor;

$monolog->pushProcessor(new IntrospectionProcessor());
```

---

## Formatting for External Log Aggregators

### Datadog

Datadog's log pipeline expects JSON with `message`, `level`, `datetime`, and `context` at the top level. Use `JsonFormatter` on the `stderr` channel and configure the Datadog Agent to collect from stderr:

```php
'stderr' => [
    'driver'    => 'monolog',
    'handler'   => Monolog\Handler\StreamHandler::class,
    'formatter' => Monolog\Formatter\JsonFormatter::class,
    'with'      => ['stream' => 'php://stderr'],
],
```

Datadog accepts ISO-8601 timestamps natively, so `datetime` from Monolog 3 `JsonFormatter` works as-is. For custom remapping, see Datadog's log-pipeline docs.

### Papertrail

Papertrail expects syslog-formatted messages. Use the built-in `papertrail` channel or configure `syslog` with a `SyslogUdpHandler`:

```php
'papertrail' => [
    'driver'       => 'monolog',
    'handler'      => Monolog\Handler\SyslogUdpHandler::class,
    'handler_with' => [
        'host' => env('PAPERTRAIL_URL'),
        'port' => env('PAPERTRAIL_PORT'),
    ],
    'formatter'    => Monolog\Formatter\LineFormatter::class,
    'formatter_with' => [
        'format' => "%channel%.%level_name%: %message% %context% %extra%\n",
    ],
],
```

### Logtail / BetterStack

Use the HTTP JSON channel or the official `logtail/logtail-laravel` package. The package installs a Monolog handler that batches and ships entries over HTTPS.

```bash
composer require logtail/logtail-laravel
```

```dotenv
LOGTAIL_SOURCE_TOKEN=your-token
LOG_CHANNEL=logtail
```

---

## Logging in Queued Jobs — Full Pattern

```php
final class SendInvoiceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $invoiceId,
        public readonly string $requestId,
    ) {}

    public function handle(): void
    {
        Context::add([
            'request_id' => $this->requestId,
            'invoice_id' => $this->invoiceId,
            'job'        => static::class,
            'attempt'    => $this->attempts(),
        ]);

        $invoice = Invoice::findOrFail($this->invoiceId);

        Log::info('invoice.email.sending');

        // ... send

        Log::info('invoice.email.sent', ['recipient' => $invoice->user->email]);
    }

    public function failed(\Throwable $e): void
    {
        Context::add('invoice_id', $this->invoiceId);
        Log::error('invoice.email.failed', [
            'error'   => $e->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }
}
```
