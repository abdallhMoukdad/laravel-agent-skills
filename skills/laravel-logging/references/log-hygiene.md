## What Never to Log

The following must never appear in any log entry, in any environment:

**Authentication and secrets**
- Passwords (plaintext or hashed)
- Password reset tokens
- API keys and API secrets
- OAuth access tokens, refresh tokens, authorization codes
- Session IDs and session tokens
- JWT tokens or cookie values
- CSRF tokens
- Private keys and signing secrets

**Payment data**
- Full credit card numbers (PAN)
- CVV / CVC codes
- Bank account numbers
- Full payment method tokens from Stripe, Braintree, etc.

**Personally Identifiable Information (PII)**
- Social security numbers / national ID numbers
- Passport numbers and government IDs
- Full date of birth (when combined with other fields)
- Full home address
- Full email addresses in contexts where logging them creates a compliance risk (GDPR, HIPAA, CCPA)
- Phone numbers
- IP addresses when they can be linked to an identified individual in a GDPR-regulated context

**Medical and legal**
- Health records or diagnoses
- Prescription information
- Legal case details

---

## Common Mistakes

### Logging `$request->all()`

Always the wrong call. `$request->all()` includes every submitted field: passwords, tokens, anything the client sent.

```php
// Wrong — logs passwords, card numbers, tokens
Log::info('request.received', $request->all());

// Correct — explicitly whitelist safe fields
Log::info('request.received', $request->only(['action', 'resource', 'locale']));
```

### Logging full exception messages blindly

Exception messages often contain SQL with parameter values, filesystem paths, or user-provided input. Review before logging:

```php
// Risky — getMessage() may expose sensitive data in some exceptions
Log::error('exception', ['message' => $e->getMessage()]);

// Safer — log class and a safe summary; store full trace in a monitoring tool
Log::error('exception.unhandled', [
    'exception' => get_class($e),
    'code'      => $e->getCode(),
    'file'      => $e->getFile(),
    'line'      => $e->getLine(),
]);
```

### Logging model attributes wholesale

```php
// Wrong — may include sensitive fillable attributes
Log::info('user.updated', $user->toArray());

// Correct — whitelist
Log::info('user.updated', [
    'user_id' => $user->id,
    'fields'  => array_keys($user->getDirty()),
]);
```

---

## Masking and Redacting Before Logging

When partial data is genuinely needed for debugging, mask before logging:

```php
// Mask all but last 4 digits of a card number
function maskCard(string $pan): string
{
    return str_repeat('*', strlen($pan) - 4) . substr($pan, -4);
}

Log::info('payment.initiated', [
    'card_last4' => substr($pan, -4),
    'amount'     => $amount,
]);
```

For email addresses when logging is required:

```php
function maskEmail(string $email): string
{
    [$local, $domain] = explode('@', $email);
    return substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 1)) . '@' . $domain;
}

// 'jo@example.com'   → 'j*@example.com'
// 'user@example.com' → 'us**@example.com'
Log::info('auth.login_failed', ['email' => maskEmail($email)]);
```

Create a `LogSanitizer` class (or helper) that applies masking rules consistently across the application rather than duplicating logic at each call site.

---

## Correct PSR-3 Level Selection — Real Scenarios

| Scenario | Level |
|----------|-------|
| SQL query took 500ms (slow query threshold exceeded) | `warning` |
| SQL query took 5,000ms | `error` |
| User logged in successfully | `info` |
| User login failed (wrong password) | `warning` |
| User account locked after 5 failed attempts | `notice` |
| Payment processed successfully | `info` |
| Payment declined by processor | `warning` |
| Payment processor returned 500 | `error` |
| Payment processor unreachable after 3 retries | `critical` |
| Job completed successfully | `info` |
| Job failed attempt 1/3 | `warning` |
| Job exhausted all retries | `error` |
| Cache miss (expected) | `debug` |
| Cache driver unreachable | `critical` |
| Deprecated method called | `warning` |
| Config value missing, using default | `notice` |
| Config value missing, system cannot start | `emergency` |
| Background import processed a row | `debug` |
| Background import completed | `info` |
| Background import failed to process a row | `warning` |
| Background import failed entirely | `error` |

---

## Performance Considerations

### Never log inside tight loops

One log call is cheap. Ten thousand log calls in a loop flush ten thousand writes to disk (or network). This will crater performance.

```php
// Wrong — one log write per iteration
foreach ($orders as $order) {
    processOrder($order);
    Log::info('order.processed', ['order_id' => $order->id]); // Do not do this
}

// Correct — log a summary after the loop
$processed = 0;
$failed = [];

foreach ($orders as $order) {
    try {
        processOrder($order);
        $processed++;
    } catch (\Throwable $e) {
        $failed[] = $order->id;
    }
}

Log::info('batch.completed', [
    'processed' => $processed,
    'failed'    => count($failed),
    'failed_ids' => $failed,
]);
```

### Lazy evaluation for expensive context

If building the context array is expensive (e.g., serializing a large object), avoid building it when the log level is below the configured threshold. Use closures (available in some drivers) or guard with level checks:

```php
// Only compute expensive debug context in debug mode
if (config('app.debug')) {
    Log::debug('query.executed', [
        'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        'bindings'  => $query->getBindings(),
    ]);
}
```

### Avoid logging in model observers for high-frequency models

If a model is saved thousands of times per minute, an observer that logs every `saved` event produces thousands of log writes. Log at the service layer where you can make a deliberate choice.

---

## Log Volume Management — Sampling

For high-frequency events where every entry is not necessary (health checks, analytics pings, routine polling), sample to reduce volume:

```php
// Log approximately 1% of health check hits
if (random_int(1, 100) === 1) {
    Log::debug('health.check', ['status' => 'ok']);
}
```

For structured sampling by user or resource ID (deterministic — same ID always logs or always skips):

```php
// Log for users where user_id mod 100 < 10 (approximately 10% of users)
if ($user->id % 100 < 10) {
    Log::debug('feature.impression', ['feature' => 'new_checkout', 'user_id' => $user->id]);
}
```

---

## Testing Log Calls

Laravel 12 core does **not** provide a `Log` test fake or any built-in log assertion API. Use one of the two approaches below.

---

### Option A — `timacdonald/log-fake` (recommended for most projects)

Install the package as a dev dependency:

```bash
composer require timacdonald/log-fake --dev
```

Then bind the fake in your test setup:

```php
use Illuminate\Support\Facades\Log;
use TiMacDonald\Log\LogFake;
use TiMacDonald\Log\LogEntry;

beforeEach(function (): void {
    LogFake::bind();
});
```

Available assertions. Each takes a closure receiving a `LogEntry` with `level`, `message`, and `context` properties.

#### `assertLogged`

At least one entry matches the callback:

```php
Log::assertLogged(fn (LogEntry $log) =>
    $log->level === 'info'
    && $log->message === 'order.placed'
    && $log->context['order_id'] === 42
);
```

#### `assertLoggedTimes`

Exactly `$count` entries match. Signature is `(callable $callback, int $count)` — note: no level argument:

```php
Log::assertLoggedTimes(
    fn (LogEntry $log) => $log->message === 'api.retry',
    3
);
```

#### `assertNotLogged`

No entries match the callback:

```php
Log::assertNotLogged(fn (LogEntry $log) =>
    $log->level === 'info' && isset($log->context['password'])
);
```

#### `assertNothingLogged`

No log calls were made at all:

```php
Log::assertNothingLogged();
```

#### Asserting a specific channel

```php
Log::channel('audit')->assertLogged(fn (LogEntry $log) =>
    $log->level === 'info'
    && $log->message === 'auth.login'
    && $log->context['user_id'] === $user->id
);
```

Note: `Log::assertLoggedMessage()` does not exist in this package. Use the closure form of `assertLogged()` and inspect `$log->message`.

---

### Option B — Laravel built-in, no package (using `Event::fake` on `MessageLogged`)

If you prefer not to add the dev dependency, you can listen to the `MessageLogged` event that Monolog fires on every log call:

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

`Event::fake()` is a Laravel core feature and requires no extra package.

---

### Full test example (Option A — `timacdonald/log-fake`)

```php
use App\Services\OrderService;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use TiMacDonald\Log\LogFake;
use TiMacDonald\Log\LogEntry;

beforeEach(function (): void {
    LogFake::bind();
});

it('logs order.placed at info level with correct context', function (): void {
    $service = app(OrderService::class);
    $order   = Order::factory()->create(['total' => 99.00]);

    $service->place($order);

    Log::assertLogged(fn (LogEntry $log) =>
        $log->level === 'info'
        && $log->message === 'order.placed'
        && $log->context['order_id'] === $order->id
        && $log->context['total'] === 99.00
    );
});

it('does not log the user email', function (): void {
    $service = app(OrderService::class);
    $order   = Order::factory()->create();

    $service->place($order);

    Log::assertNotLogged(fn (LogEntry $log) =>
        $log->level === 'info' && array_key_exists('email', $log->context)
    );
});

it('logs a warning when the payment is retried', function (): void {
    $service = app(OrderService::class);

    $service->retryPayment($orderId = 99);

    Log::assertLogged(fn (LogEntry $log) =>
        $log->level === 'warning'
        && $log->message === 'payment.retrying'
        && $log->context['order_id'] === $orderId
    );
});

it('writes audit entry on login', function (): void {
    $user = User::factory()->create();

    login($user);

    Log::channel('audit')->assertLogged(fn (LogEntry $log) =>
        $log->level === 'info'
        && $log->message === 'auth.login'
        && $log->context['user_id'] === $user->id
    );
});

it('does not write to default channel for audit events', function (): void {
    $user = User::factory()->create();

    login($user);

    // Audit events must not bleed into the default log
    Log::assertNotLogged(fn (LogEntry $log) =>
        $log->level === 'info' && $log->message === 'auth.login'
    );
});
```
