## `bootstrap/app.php` — Full `withExceptions()` Configuration

All exception handling is configured here. The `Exceptions` object passed to the closure exposes every registration method.

```php
<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ...
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // 1. Force JSON for all /api/* routes
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request, Throwable $e): bool => $request->is('api/*')
        );

        // 2. Suppress expected, user-caused exceptions from the log
        $exceptions->dontReport([
            ModelNotFoundException::class,
            ValidationException::class,
            AuthorizationException::class,
            AuthenticationException::class,
        ]);

        // 3. Custom reporter per exception type
        // Note: dontReport is checked first — if PaymentGatewayException is also in dontReport,
        // this callback will never execute. Remove it from dontReport to enable custom reporting.
        $exceptions->report(function (PaymentGatewayException $e): void {
            \Log::channel('payments')->error($e->getMessage(), [
                'code'  => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
        });

        // 4. Throttle error reports to prevent Sentry/Flare spam on repeated failures
        $exceptions->throttle(function (Throwable $e) {
            // Report at most once per minute per exception class
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(1)
                ->by($e::class);
        });

        // 5. Specific renderers — registered before the catch-all
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return response()->json(['message' => 'Resource not found.'], 404);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            // Override default shape to match the API contract
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors'  => $e->errors(),
            ], 422);
        });

        // 6. Catch-all — always last; never leak internals
        $exceptions->render(function (Throwable $e, Request $request) {
            return response()->json(['message' => 'An unexpected error occurred.'], 500);
        });

    })->create();
```

---

## `reportable()` and `renderable()`

`reportable()` and `renderable()` are fluent aliases for `report()` and `render()`. They return the `Exceptions` instance for chaining and accept the same closures.

```php
$exceptions
    ->reportable(function (PaymentException $e): void {
        \Log::channel('payments')->error($e->getMessage());
    })
    ->renderable(function (PaymentException $e, Request $request) {
        return response()->json([
            'message' => 'Payment failed.',
            'code'    => 'PAYMENT_ERROR',
        ], 402);
    });
```

Use the shorter `report()` / `render()` forms for new code — they are identical at runtime.

---

## `$exceptions->throttle()` — Prevent Report Spam

Use `throttle()` to rate-limit how often an exception type is reported to external trackers. Without it, a single bad deploy can flood Sentry with thousands of identical events.

```php
use Illuminate\Cache\RateLimiting\Limit;

$exceptions->throttle(function (Throwable $e): Limit|null {
    if ($e instanceof \App\Exceptions\PaymentGatewayException) {
        // Report at most 5 times per minute for this type
        return Limit::perMinute(5)->by($e::class);
    }

    // Return null to apply no throttle for other exception types
    return null;
});
```

---

## Domain Exception Class Anatomy

A complete domain exception with typed constructor, `render()`, `report()`, and `context()`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InsufficientFundsException extends \RuntimeException
{
    /**
     * Typed constructor provides rich context for logging and response building.
     * Store value objects or primitives — never Eloquent models.
     */
    public function __construct(
        private readonly int $requiredCents,
        private readonly int $availableCents,
        private readonly string $currency = 'USD',
    ) {
        parent::__construct(
            sprintf(
                'Insufficient funds: required %d %s, available %d %s.',
                $requiredCents,
                $currency,
                $availableCents,
                $currency,
            )
        );
    }

    /**
     * HTTP response returned to the client.
     * 402 Payment Required for fund-related rejections.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code'    => 'INSUFFICIENT_FUNDS',
        ], 402);
    }

    /**
     * Custom log entry with structured context.
     * Omit this method to use the default reporter.
     * Return false to suppress logging entirely.
     */
    public function report(): void
    {
        \Log::warning($this->getMessage(), $this->context());
    }

    /**
     * Structured data appended to every log entry for this exception.
     * Laravel's default reporter merges context() automatically.
     */
    public function context(): array
    {
        return [
            'required_cents'  => $this->requiredCents,
            'available_cents' => $this->availableCents,
            'currency'        => $this->currency,
        ];
    }
}
```

---

## HTTP Status Code Mapping for Domain Exceptions

| Status | Code | Domain scenario |
|---|---|---|
| 400 | Bad Request | Malformed request that is not a validation error |
| 402 | Payment Required | Insufficient funds, expired payment method |
| 403 | Forbidden | User authenticated but action not allowed |
| 409 | Conflict | Duplicate resource, concurrent modification |
| 422 | Unprocessable | Business rule violation with field-level details |
| 429 | Too Many Requests | Application-level rate limiting |
| 503 | Service Unavailable | External dependency down |

```php
// 409 Conflict — duplicate subscription
final class DuplicateSubscriptionException extends \RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'An active subscription already exists.',
            'code'    => 'DUPLICATE_SUBSCRIPTION',
        ], 409);
    }

    public function report(): false
    {
        return false; // expected state — no log needed
    }
}

// 429 Too Many Requests — application-level throttle
final class RateLimitExceededException extends \RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Too many requests. Please wait before retrying.',
            'code'    => 'RATE_LIMIT_EXCEEDED',
        ], 429);
    }

    public function report(): false
    {
        return false;
    }
}
```

---

## `HttpException` — When to Use It

Extend `HttpException` only when the HTTP status code is the only meaningful information and no custom `code` or contextual data is needed. Laravel's `abort()` helper throws `HttpException` internally.

```php
// Acceptable — status-only, no domain context
abort(410, 'This resource has been permanently removed.');

// Better for domain logic — use a named exception class instead
throw new ContentRemovedPermanentlyException($contentId);
```

Never extend `HttpException` for business rule violations. Use `RuntimeException` and add a `render()` method.

---

## Infrastructure Exception Pattern

Wrap external service exceptions at the integration boundary. Catch the vendor SDK's exception, throw a domain exception with the original as `$previous`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use Stripe\Exception\ApiConnectionException;

final class StripePaymentService
{
    public function charge(int $amountCents, string $paymentMethodId): string
    {
        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount'         => $amountCents,
                'currency'       => 'usd',
                'payment_method' => $paymentMethodId,
                'confirm'        => true,
            ]);

            return $intent->id;
        } catch (ApiConnectionException $e) {
            // Wrap in a domain exception — callers never depend on Stripe's hierarchy
            throw new PaymentGatewayException(
                'Payment gateway connection failed.',
                previous: $e,
            );
        }
    }
}
```

```php
// app/Exceptions/PaymentGatewayException.php
final class PaymentGatewayException extends \RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Payment processing is temporarily unavailable.',
            'code'    => 'PAYMENT_GATEWAY_ERROR',
        ], 503);
    }

    public function report(): void
    {
        // Always log infrastructure failures — they are application bugs
        \Log::channel('payments')->error($this->getMessage(), [
            'previous' => $this->getPrevious()?->getMessage(),
        ]);
    }
}
```

---

## Exception Hierarchy Reference

```
\RuntimeException (base for all domain and infrastructure exceptions)
├── App\Exceptions\InsufficientFundsException      — domain / business rule
├── App\Exceptions\SubscriptionExpiredException    — domain / business rule
├── App\Exceptions\AccountSuspendedException       — domain / business rule
├── App\Exceptions\DuplicateSubscriptionException  — domain / conflict
├── App\Exceptions\PaymentGatewayException         — infrastructure
├── App\Exceptions\EmailDeliveryException          — infrastructure
└── App\Exceptions\RateLimitExceededException      — application-level throttle

\Illuminate\Auth\AuthenticationException           — never subclass; configure in bootstrap/app.php
\Illuminate\Auth\Access\AuthorizationException     — never subclass; configure in bootstrap/app.php
\Illuminate\Validation\ValidationException         — never subclass; configure in bootstrap/app.php
\Illuminate\Database\Eloquent\ModelNotFoundException — never subclass; configure in bootstrap/app.php
```
