---
name: laravel-logging
description: This skill should be used when the user asks to "add logging", "log an event", "set up a log channel", "configure logging", "write to a log", "use Log facade", "add audit logging", "log user activity", "set up structured logging", or when working with Laravel's logging system, Monolog, or log configuration.
version: 1.0.0
---

## Log Levels

Use the correct PSR-3 level for every call. Never default to `Log::info()` for everything.

| Level | When to use |
|-------|-------------|
| `debug` | Detailed diagnostic info — dev/staging only, never in production by default |
| `info` | Routine expected events: user logged in, order placed, job completed |
| `notice` | Normal but significant: config changed, new user from unusual country |
| `warning` | Unexpected but recoverable: deprecated API used, retry succeeded after failure |
| `error` | Runtime errors that don't require immediate action: failed payment, third-party API call failed |
| `critical` | Serious failures: component unavailable, unexpected exception in core path |
| `alert` | Immediate action required: entire database unreachable, payment processor down |
| `emergency` | System is unusable |

```php
Log::debug('query.slow', ['sql' => $sql, 'ms' => $ms]);
Log::info('order.placed', ['order_id' => $order->id]);
Log::warning('api.retry_succeeded', ['attempt' => 3, 'service' => 'stripe']);
Log::error('payment.failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
Log::critical('cache.unavailable', ['driver' => config('cache.default')]);
Log::alert('database.unreachable', ['connection' => 'mysql']);
```

---

## Structured Context

Always pass context as the second argument. Never interpolate data into the message string.

```php
// Wrong — unstructured, unsearchable, impossible to filter in a log aggregator
Log::info("User {$user->id} placed order {$order->id}");

// Correct — message is a stable dot-notation event name; data is in context
Log::info('order.placed', [
    'user_id'  => $user->id,
    'order_id' => $order->id,
    'total'    => $order->total,
]);
```

Use dot-notation event names as the message string. They are stable identifiers that log aggregators can index and filter on. Never include runtime values in the message string itself.

---

## Request-Scoped Context with `Log::shareContext()`

Call `Log::shareContext()` once per request in middleware rather than repeating shared context on every individual log call. Context set this way is automatically merged into every subsequent log entry **across all channels** for the lifetime of that request.

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
            'request_id' => Str::uuid()->toString(),
            'user_id'    => $request->user()?->id,
            'ip'         => $request->ip(),
            'route'      => $request->route()?->getName(),
        ]);

        return $next($request);
    }
}
```

Register this middleware early in the middleware stack (in `bootstrap/app.php`) so every log call within the request automatically includes these fields.

---

## Never Log Sensitive Data

Never log any of the following, even for debugging:

- Passwords or password hashes
- API keys, tokens, or secrets
- Credit card numbers or CVVs
- Social security or national ID numbers
- Full email addresses when used in a PII-sensitive context
- Session tokens or authentication cookies
- Any field marked as sensitive in your data model

When a request payload must be logged, always explicitly whitelist the fields you intend to log:

```php
// Wrong — logs everything including passwords, tokens
Log::info('user.register', $request->all());

// Correct — explicit whitelist
Log::info('user.register', $request->only(['name', 'country', 'plan']));
```

Mask values before logging when a partial representation is needed for debugging:

```php
// Show only the last 4 digits of a card number
Log::info('payment.initiated', [
    'card_last4' => substr($cardNumber, -4),
    'amount'     => $amount,
]);
```

---

## Log Channels

Configure channels in `config/logging.php`. Key rules:

- Use `stack` in production to fan out to multiple drivers simultaneously.
- Use `daily` (not `single`) for file-based logging so log files rotate by day.
- Use `stderr` for containerized and cloud deployments — stdout/stderr is captured by the platform.
- Use `slack` (or `discord`) for `critical` and `emergency` level alerts.
- Never log `debug` to a persistent channel in production; gate it behind environment checks.

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver'   => 'stack',
        'channels' => ['daily', 'slack'],
    ],
    'daily' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/laravel.log'),
        'level'  => env('LOG_LEVEL', 'debug'),
        'days'   => 14,
    ],
    'slack' => [
        'driver'   => 'slack',
        'url'      => env('LOG_SLACK_WEBHOOK_URL'),
        'username' => 'Laravel',
        'emoji'    => ':boom:',
        'level'    => 'critical',
    ],
    'stderr' => [
        'driver'    => 'monolog',
        'handler'   => StreamHandler::class,
        'formatter' => JsonFormatter::class,
        'with'      => ['stream' => 'php://stderr'],
        'level'     => env('LOG_LEVEL', 'debug'),
    ],
],
```

Switch channels per environment using the `LOG_CHANNEL` environment variable:

```dotenv
# .env (production container)
LOG_CHANNEL=stderr
LOG_LEVEL=info

# .env (local)
LOG_CHANNEL=daily
LOG_LEVEL=debug
```

---

## Dedicated Audit Channel

Create a separate `audit` channel for security-sensitive events. Never mix audit events into the default application log — they have different retention requirements and must be tamper-evident.

Events that belong in the audit log: login attempts (success and failure), permission changes, role assignments, data exports, bulk deletions, admin actions.

```php
// config/logging.php
'audit' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/audit.log'),
    'level'  => 'info',
    'days'   => 90,
],
```

Write to the audit channel explicitly:

```php
Log::channel('audit')->info('auth.login', [
    'user_id' => $user->id,
    'ip'      => $request->ip(),
    'success' => true,
]);

// Audit channels may log masked PII under appropriate retention/access policies.
// Always apply maskEmail() or equivalent when in doubt.
Log::channel('audit')->warning('auth.login_failed', [
    'email'   => maskEmail($request->input('email', '')),
    'ip'      => $request->ip(),
    'reason'  => 'invalid_password',
]);

Log::channel('audit')->info('permission.granted', [
    'actor_id'  => $actor->id,
    'target_id' => $target->id,
    'permission' => 'admin',
]);
```

---

## Where to Log — Service and Job Layer Only

Never log inside Eloquent models or helper functions. Models and helpers should throw exceptions; the caller decides whether and how to log.

Logging inside a model creates hidden side effects, makes unit testing hard (tests must configure log fakes to avoid filesystem writes), and leaks logging concerns into the data layer.

```php
// Wrong — logging inside a model method
class Order extends Model
{
    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
        Log::info('order.cancelled', ['order_id' => $this->id]); // Do not do this
    }
}

// Correct — log at the service layer
class OrderService
{
    public function cancel(Order $order): void
    {
        $order->cancel(); // model just mutates state
        Log::info('order.cancelled', ['order_id' => $order->id]);
    }
}
```

The same rule applies to helpers, traits, and form requests — log in the service, action, or job that orchestrates the work.

---

## Testing Logs

Laravel 12 core does **not** provide `Log::fake()`, `Log::assertLogged()`, or any built-in log assertion API. Use one of the two approaches below.

### Option A — `spatie/laravel-log-fake` (recommended)

Provides a clean assertion API. Install as a dev dependency:

```bash
composer require spatie/laravel-log-fake --dev
```

```php
use Illuminate\Support\Facades\Log;
use Spatie\LaravelLogFake\FakeLogger;

beforeEach(function (): void {
    Log::swap(new FakeLogger());
});

it('logs order.placed at info level', function (): void {
    $order = Order::factory()->create();

    placeOrder($order);

    // Assert a specific message was logged at a level
    Log::assertLoggedMessage('info', 'order.placed');

    // Assert with context inspection
    Log::assertLogged('info', fn (string $message, array $context): bool
        => $message === 'order.placed' && $context['order_id'] === $order->id
    );
});

it('does not log sensitive data', function (): void {
    registerUser(['email' => 'user@example.com', 'password' => 'secret']);

    Log::assertNotLogged('info', fn (string $message, array $context): bool
        => isset($context['password'])
    );
});

it('logs to the audit channel on login', function (): void {
    login(User::factory()->create());

    Log::channel('audit')->assertLogged('info', fn (string $message, array $context): bool
        => $message === 'auth.login'
    );
});
```

Available assertions (from `spatie/laravel-log-fake`):
- `Log::assertLogged($level, $callback)` — at least one entry matches
- `Log::assertLoggedMessage($level, $message)` — at least one entry with that exact message
- `Log::assertLoggedTimes($level, $times, $callback)` — exact count
- `Log::assertNotLogged($level, $callback)` — no matching entry
- `Log::assertNothingLogged()` — no log calls at all

### Option B — Laravel built-in, no package (using `Event::fake` on `MessageLogged`)

```php
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

it('logs order.placed at info level', function (): void {
    Event::fake([MessageLogged::class]);

    placeOrder($order);

    Event::assertDispatched(MessageLogged::class, function ($event) use ($order): bool {
        return $event->level === 'info'
            && $event->message === 'order.placed'
            && ($event->context['order_id'] ?? null) === $order->id;
    });
});
```

---

## Additional Resources

- `references/channels.md` — Complete `config/logging.php` reference covering all channel drivers, the `tap` key, log stacks, environment-specific switching, and the `LOG_CHANNEL` variable.
- `references/structured-logging.md` — Deep dive on structured logging: `withContext()` middleware pattern, request ID propagation, context in queued jobs, `shareContext()` vs `withContext()`, Monolog processors, and formatting for external log aggregators.
- `references/log-hygiene.md` — What never to log, masking/redacting helpers, correct PSR-3 level selection for real scenarios, performance considerations, log volume sampling, and log assertion approaches (both `spatie/laravel-log-fake` and `Event::fake` options) with examples.
