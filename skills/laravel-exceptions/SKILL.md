---
name: laravel-exceptions
description: This skill should be used when the user asks to "handle exceptions", "create a custom exception", "return consistent API errors", "configure error handling", "catch an exception", "render a 404 response", "set up exception handling", or when dealing with error handling in a Laravel 12 API.
version: 1.0.0
---

## Exception Handling in Laravel 12

### `bootstrap/app.php` Is the Exception Handler

`app/Exceptions/Handler.php` does not exist in Laravel 11 or 12. All exception configuration lives in `bootstrap/app.php` inside `->withExceptions()`. Register renderers, reporters, ignore rules, and JSON forcing here — never elsewhere.

```php
->withExceptions(function (Exceptions $exceptions): void {
    // renderers, reporters, dontReport, shouldRenderJsonWhen
})
```

---

## Force JSON for All API Routes

By default Laravel returns HTML error pages. APIs require JSON for every error response. Use `shouldRenderJsonWhen()` to force JSON for all `/api/*` requests without touching individual exception types:

```php
use Illuminate\Http\Request;

$exceptions->shouldRenderJsonWhen(
    fn(Request $request, Throwable $e): bool => $request->is('api/*')
);
```

Place this call at the top of the `withExceptions` block so it applies before any other renderer.

---

## Standard API Error Response Shape

All error responses in this codebase follow one shape:

```json
{ "message": "Human-readable description.", "errors": {}, "code": "ERROR_CODE" }
```

- `message` — always present, always a string
- `errors` — present on validation errors; keyed by field name, value is array of strings
- `code` — present on domain exceptions; `SCREAMING_SNAKE_CASE`; omitted on generic HTTP errors

---

## Global Renderers in `bootstrap/app.php`

Register a renderer for each exception type that needs a specific JSON shape. Keep renderers thin — they only build the response, never business logic:

```php
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

$exceptions->render(function (ModelNotFoundException $e, Request $request) {
    return response()->json(['message' => 'Resource not found.'], 404);
});

$exceptions->render(function (AuthenticationException $e, Request $request) {
    return response()->json(['message' => 'Unauthenticated.'], 401);
});

$exceptions->render(function (AuthorizationException $e, Request $request) {
    return response()->json(['message' => 'This action is unauthorized.'], 403);
});
```

Laravel renders `ValidationException` as JSON automatically when the request expects JSON, but override the shape here if the default structure does not match the API contract.

Add a catch-all `Throwable` renderer last to guarantee a 500 JSON response — never leak stack traces or internal messages in production:

```php
$exceptions->render(function (Throwable $e, Request $request) {
    return response()->json(['message' => 'An unexpected error occurred.'], 500);
});
```

---

## `dontReport` — Suppress Noisy Expected Exceptions

Expected, user-caused exceptions do not need Sentry or Flare tickets. Suppress them from the log:

```php
$exceptions->dontReport([
    ModelNotFoundException::class,
    ValidationException::class,
    AuthorizationException::class,
    AuthenticationException::class,
]);
```

These represent user errors — a missing record, invalid input, an unauthorized action. They are not application bugs. Reserve log/tracker noise for real failures.

---

## Domain Exceptions

Create one exception class per distinct business rule violation. Place them in `app/Exceptions/`. Extend `RuntimeException` — not Laravel's `HttpException` — unless the HTTP status code is the only meaningful information the exception carries.

```bash
# No artisan command — create by hand in app/Exceptions/
```

Use specific, descriptive names:

- `InsufficientFundsException` — not `PaymentException`
- `SubscriptionExpiredException` — not `AppException`
- `AccountSuspendedException` — not `UserException`

### `render()` on the Exception Class

Add a `render()` method directly on the exception class. Laravel 12 auto-discovers it — no registration in `bootstrap/app.php` required:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InsufficientFundsException extends \RuntimeException
{
    public function __construct(
        private readonly int $requiredCents,
        private readonly int $availableCents,
    ) {
        parent::__construct('Insufficient funds for this transaction.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code'    => 'INSUFFICIENT_FUNDS',
        ], 402);
    }

    public function report(): false
    {
        return false; // expected business condition — do not log
    }
}
```

### `report()` on the Exception Class

Add `report()` to control logging for that exception type specifically. Return `false` to suppress logging entirely. Return nothing (or omit the method) to use the default reporter. Use it to send the exception to a specific log channel:

```php
public function report(): void
{
    \Log::channel('payments')->error($this->getMessage(), [
        'required'  => $this->requiredCents,
        'available' => $this->availableCents,
    ]);
}
```

An exception can have a `render()` method, a `report()` method, both, or neither.

---

## `report()` vs `render()` — Clear Separation

| Method | Controls |
|---|---|
| `report()` | Whether and how the exception is logged / sent to Sentry / Flare |
| `render()` | The HTTP response returned to the client |

These are independent. Suppressing logging (`report(): false`) does not change the HTTP response. Customizing the HTTP response does not silence the log entry.

---

## Never Swallow Exceptions

Never catch an exception and do nothing:

```php
// Wrong — silent failure
try {
    $this->chargeCard($amount);
} catch (\Exception $e) {
    // empty
}
```

Always either re-throw, log, or convert to a user-facing response:

```php
// Correct — convert to domain exception
try {
    $this->chargeCard($amount);
} catch (GatewayTimeoutException $e) {
    throw new PaymentGatewayException('Payment gateway unavailable.', previous: $e);
}
```

Catch specific exception types. Only catch `\Throwable` or `\Exception` at the outermost application boundary (the global catch-all renderer in `bootstrap/app.php`).

---

## Infrastructure Exceptions

Wrap external service failures in domain-level infrastructure exceptions. This decouples the application from the vendor SDK's exception hierarchy and produces consistent error codes for clients:

```php
final class PaymentGatewayException extends \RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Payment processing is temporarily unavailable.',
            'code'    => 'PAYMENT_GATEWAY_ERROR',
        ], 503);
    }
}
```

---

## Exception Hierarchy

| Category | Examples | Base class |
|---|---|---|
| Domain | `InsufficientFundsException`, `AccountSuspendedException` | `RuntimeException` |
| Infrastructure | `PaymentGatewayException`, `EmailDeliveryException` | `RuntimeException` |
| Validation | Laravel's `ValidationException` | Never recreate |
| Auth | `AuthenticationException`, `AuthorizationException` | Never recreate |

Never subclass Laravel's built-in auth or validation exceptions. Configure their JSON shapes via `$exceptions->render()` in `bootstrap/app.php`.

---

## Artisan

No dedicated `make:exception` command exists in Laravel 12. Create exception classes by hand in `app/Exceptions/`.

---

## Additional Resources

- `references/exception-handling.md` — Full `bootstrap/app.php` configuration reference: all `withExceptions()` methods, `throttle()`, custom reporting per exception type, `reportable()` and `renderable()`, complete domain exception anatomy with typed constructors and `context()`, HTTP status code mapping, and global renderer examples.
- `references/api-error-responses.md` — Standard API error response contract: all response shapes by status code, machine-readable error codes, customizing `ValidationException` shape, preventing internal detail leaks in production, and Pest test patterns for every error scenario.
