## Standard API Error Response Contract

Every error response in the API follows this structure:

```json
{
    "message": "Human-readable description of the error.",
    "errors": {},
    "code": "MACHINE_READABLE_CODE"
}
```

- `message` — always present; always a plain string; safe to display in a UI
- `errors` — present only for validation errors; keyed by field name; value is an array of strings
- `code` — present only for domain exceptions; `SCREAMING_SNAKE_CASE`; omitted on generic HTTP errors

---

## Error Response Shapes by Status Code

### 400 Bad Request

```json
{ "message": "The request could not be understood." }
```

### 401 Unauthenticated

```json
{ "message": "Unauthenticated." }
```

Triggered by `AuthenticationException`. No `code` — the status code is sufficient for auth routing.

### 403 Forbidden

```json
{ "message": "This action is unauthorized." }
```

Triggered by `AuthorizationException`. No `code`.

### 404 Not Found

```json
{ "message": "Resource not found." }
```

Triggered by `ModelNotFoundException`. No `code`.

### 422 Validation Error

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "email": ["The email field is required.", "The email must be a valid email address."],
        "amount": ["The amount must be at least 1."]
    }
}
```

Laravel generates this shape automatically. Override in `bootstrap/app.php` if the contract differs.

### Domain Error (4xx)

```json
{
    "message": "Insufficient funds for this transaction.",
    "code": "INSUFFICIENT_FUNDS"
}
```

Every domain exception MUST include a `code`. Clients use the `code` for programmatic UI logic — they do not parse `message` strings.

### 500 Server Error

```json
{ "message": "An unexpected error occurred." }
```

Never include the actual exception message, stack trace, or any internal detail. Log the real error server-side via Sentry or Flare.

---

## Machine-Readable Error Codes

All `code` values are `SCREAMING_SNAKE_CASE` strings. Document every code in the API reference. Clients depend on stability — never rename a code once it is published.

| Code | Status | Meaning |
|---|---|---|
| `INSUFFICIENT_FUNDS` | 402 | Account balance below required amount |
| `SUBSCRIPTION_EXPIRED` | 402 | Subscription period has ended |
| `DUPLICATE_SUBSCRIPTION` | 409 | Active subscription already exists |
| `ACCOUNT_SUSPENDED` | 403 | Account has been suspended by an admin |
| `RATE_LIMIT_EXCEEDED` | 429 | Too many requests from this client |
| `PAYMENT_GATEWAY_ERROR` | 503 | Upstream payment processor unavailable |
| `EMAIL_DELIVERY_FAILED` | 503 | Transactional email could not be sent |

Throw dedicated exception classes — never generate `code` values inline in controllers.

---

## Implementing the Standard Shape in `bootstrap/app.php`

```php
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

->withExceptions(function (Exceptions $exceptions): void {

    $exceptions->shouldRenderJsonWhen(
        fn(Request $request, Throwable $e): bool => $request->is('api/*')
    );

    $exceptions->dontReport([
        ModelNotFoundException::class,
        ValidationException::class,
        AuthorizationException::class,
        AuthenticationException::class,
    ]);

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
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors'  => $e->errors(),
        ], 422);
    });

    // Catch-all — last renderer; never expose internals
    $exceptions->render(function (Throwable $e, Request $request) {
        return response()->json(['message' => 'An unexpected error occurred.'], 500);
    });

})
```

---

## Customizing the `ValidationException` Shape

### Flat Error List

Some clients prefer a flat array of error strings rather than a nested field map:

```php
$exceptions->render(function (ValidationException $e, Request $request) {
    $flat = collect($e->errors())
        ->flatMap(fn(array $messages): array => $messages)
        ->values()
        ->all();

    return response()->json([
        'message' => 'The given data was invalid.',
        'errors'  => $flat,
    ], 422);
});
```

### First Error Only per Field

```php
$exceptions->render(function (ValidationException $e, Request $request) {
    $first = collect($e->errors())
        ->map(fn(array $messages): string => $messages[0])
        ->all();

    return response()->json([
        'message' => 'The given data was invalid.',
        'errors'  => $first,
    ], 422);
});
```

---

## Never Leak Internals in Production

Set `APP_DEBUG=false` in all production environments. Laravel suppresses stack traces in rendered responses when `APP_DEBUG` is false.

Never pass `$e->getMessage()` to a 500 response. An unhandled `PDOException` message may contain the database DSN, credentials, or schema details:

```php
// Wrong — leaks internal error message
$exceptions->render(function (Throwable $e, Request $request) {
    return response()->json(['message' => $e->getMessage()], 500);
});

// Correct — generic message, real error goes to Sentry/Flare
$exceptions->render(function (Throwable $e, Request $request) {
    return response()->json(['message' => 'An unexpected error occurred.'], 500);
});
```

For 4xx domain exceptions it is safe to return `$e->getMessage()` because the message is authored in the exception constructor and never contains internal data:

```php
public function render(Request $request): JsonResponse
{
    return response()->json([
        'message' => $this->getMessage(), // authored in constructor — safe
        'code'    => 'INSUFFICIENT_FUNDS',
    ], 402);
}
```

---

## Sentry and Flare Integration

Install the Laravel Sentry SDK and errors are reported automatically via the `report()` pipeline. `dontReport` exceptions are skipped before the SDK receives them.

```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --dsn=https://...
```

Use `$exceptions->throttle()` to prevent duplicate alerts from filling the inbox when a single root cause triggers hundreds of exceptions per minute.

---

## Testing Error Responses in Pest

### 404 — Model Not Found

```php
it('returns 404 when the post does not exist', function (): void {
    $this->getJson('/api/posts/99999')
        ->assertNotFound()
        ->assertJson(['message' => 'Resource not found.']);
});
```

### 401 — Unauthenticated

```php
it('returns 401 when no token is provided', function (): void {
    $this->getJson('/api/posts')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});
```

### 403 — Forbidden

```php
it('returns 403 when user cannot delete another user post', function (): void {
    $post = Post::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/posts/{$post->id}")
        ->assertForbidden()
        ->assertJson(['message' => 'This action is unauthorized.']);
});
```

### 422 — Validation Error

```php
it('returns 422 with field errors when amount is missing', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/orders', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});
```

### Domain Exception — Custom Code and Status

```php
it('returns 402 with INSUFFICIENT_FUNDS code when balance is too low', function (): void {
    $user = User::factory()->create(['balance_cents' => 100]);

    $this->actingAs($user)
        ->postJson('/api/orders', ['amount_cents' => 5000])
        ->assertStatus(402)
        ->assertJson([
            'message' => 'Insufficient funds for this transaction.',
            'code'    => 'INSUFFICIENT_FUNDS',
        ]);
});
```

### 500 — Server Error Does Not Leak Internals

```php
it('returns a generic 500 message and does not expose exception details', function (): void {
    // Force an unexpected error by mocking a service to throw
    $this->mock(PaymentService::class)
        ->shouldReceive('charge')
        ->andThrow(new \RuntimeException('DB connection string: mysql://root:secret@...'));

    $this->actingAs(User::factory()->create())
        ->postJson('/api/payments', ['amount' => 100])
        ->assertStatus(500)
        ->assertJson(['message' => 'An unexpected error occurred.'])
        ->assertJsonMissing(['mysql', 'secret']); // internal detail must not leak
});
```

### Assert No Validation Error for a Specific Field

```php
it('does not require a description field', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/posts', ['title' => 'Hello'])
        ->assertJsonMissingValidationErrors(['description']);
});
```

---

## Response Header Conventions

Always return `Content-Type: application/json` for every error response. Laravel sets this automatically when `response()->json()` is used. Confirm with:

```bash
curl -I -H "Accept: application/json" http://localhost/api/missing-route
# Content-Type: application/json
```

For rate-limited responses (429), include `Retry-After` if the retry window is known:

```php
public function render(Request $request): JsonResponse
{
    return response()->json([
        'message' => 'Too many requests.',
        'code'    => 'RATE_LIMIT_EXCEEDED',
    ], 429)->header('Retry-After', 60);
}
```
