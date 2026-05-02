## Rate Limiting in Laravel

---

## Defining Named Rate Limiters

### Global API Limiter with Per-User / Per-IP Fallback

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

// AppServiceProvider::boot()
RateLimiter::for('api', function (Request $request): Limit {
    return $request->user()
        ? Limit::perMinute(120)->by($request->user()->id)
        : Limit::perMinute(20)->by($request->ip());
});
```

Authenticated users get a higher limit keyed to their ID (per-user isolation). Guests get a tighter limit keyed to IP (shared among their requests only).

### Sensitive Action Limiters

```php
RateLimiter::for('login', function (Request $request): Limit {
    return Limit::perMinute(5)->by($request->ip());
});

RateLimiter::for('password-reset', function (Request $request): Limit {
    return Limit::perHour(3)->by($request->input('email') . '|' . $request->ip());
});

RateLimiter::for('token-create', function (Request $request): Limit {
    return $request->user()
        ? Limit::perDay(10)->by($request->user()->id)
        : Limit::perMinute(1)->by($request->ip());
});
```

Key password-reset attempts on both the email address and IP to prevent both credential stuffing and account enumeration across IPs.

### Tiered Limits

Return an array of `Limit` objects to enforce multiple independent windows:

```php
RateLimiter::for('uploads', function (Request $request): array {
    return [
        Limit::perMinute(10)->by($request->user()->id),   // burst cap
        Limit::perDay(200)->by($request->user()->id),     // daily cap
    ];
});
```

Both limits are evaluated independently. The request is blocked as soon as either limit is exceeded.

---

## Applying Rate Limiters to Routes

```php
// Single route
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Route group
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::apiResource('posts', PostController::class);
    Route::apiResource('comments', CommentController::class);
});

// Separate limiter per group
Route::middleware('throttle:uploads')->group(function () {
    Route::post('/media', [MediaController::class, 'store']);
});
```

Never use the inline `throttle:60,1` syntax for production routes. Always reference a named limiter so limits can be changed in one place.

---

## Different Limits for Authenticated vs Guest Users

The closure receives the `Request` object, giving full access to the authenticated user, request headers, and input. Use this to express business rules:

```php
RateLimiter::for('search', function (Request $request): Limit {
    $user = $request->user();

    if ($user?->subscription === 'enterprise') {
        return Limit::none();  // unlimited for enterprise plan
    }

    if ($user?->subscription === 'pro') {
        return Limit::perMinute(60)->by($user->id);
    }

    if ($user) {
        return Limit::perMinute(10)->by($user->id);
    }

    return Limit::perMinute(5)->by($request->ip());
});
```

`Limit::none()` disables the limiter for that request — use for premium tiers.

---

## Non-HTTP Contexts: Queue Jobs and Console Commands

`RateLimiter::attempt()` checks and increments the counter without an HTTP request:

```php
use Illuminate\Support\Facades\RateLimiter;

// In a queue job
public function handle(): void
{
    $executed = RateLimiter::attempt(
        key: 'send-email:' . $this->userId,
        maxAttempts: 5,
        callback: function () {
            $this->sendEmail();
        },
        decaySeconds: 60
    );

    if (! $executed) {
        // Rate limit exceeded — re-queue with a delay
        $this->release(30);
    }
}
```

```php
// In an Artisan command
public function handle(): void
{
    $key = 'external-api-sync';

    if (RateLimiter::tooManyAttempts($key, maxAttempts: 10)) {
        $seconds = RateLimiter::availableIn($key);
        $this->warn("Rate limited. Retry in {$seconds}s.");
        return;
    }

    RateLimiter::hit($key, decaySeconds: 60);

    $this->syncExternalApi();
}
```

---

## 429 Response and `Retry-After` Header

When a route is throttled, Laravel automatically returns a `429 Too Many Requests` response with a `Retry-After` header (seconds until the window resets) and an `X-RateLimit-Limit` / `X-RateLimit-Remaining` header pair.

To customize the 429 response globally, override `throttleWithRedirectTo` or register a custom response in the exception handler:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

// bootstrap/app.php (Laravel 11+)
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
        return response()->json([
            'message'     => 'Too many requests.',
            'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
        ], 429, $e->getHeaders());
    });
})
```

---

## Manually Clearing a Rate Limit

```php
// Clear the limit for a specific key (e.g., after a successful login)
RateLimiter::clear('login:' . $request->ip());

// Inspect remaining attempts
$remaining = RateLimiter::remaining('login:' . $request->ip(), maxAttempts: 5);

// Seconds until the window resets
$seconds = RateLimiter::availableIn('login:' . $request->ip());
```

Clear the login rate limit after a successful authentication so legitimate users are not locked out if they mistyped their password several times before succeeding.

---

## Testing Rate Limiters

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    // The ThrottleRequests middleware hashes limiter keys by default
    // (md5($limiterName . $limit->key)), so calls like
    //   RateLimiter::clear('login:127.0.0.1')
    // miss the actual cache entry and clear nothing. Flush the cache instead.
    Cache::flush();

    // Alternative: disable key hashing in setUp, then per-key clear works:
    //   \Illuminate\Routing\Middleware\ThrottleRequests::shouldHashKeys(false);
    //   RateLimiter::clear('login:127.0.0.1');
});

it('blocks after 5 failed login attempts', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
             ->assertStatus(401);
    }

    $this->postJson('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
         ->assertStatus(429)
         ->assertJsonFragment(['message' => 'Too many requests.']);
});

it('does not block legitimate users below the limit', function (): void {
    // NOTE: Calling RateLimiter::for('api', ...) inside a test re-registers the named
    // limiter globally for the entire test run, which can pollute other tests that
    // rely on the original 'api' limiter definition. Either call it only in beforeEach
    // with a matching restore, or use a dedicated test-scoped limiter name (e.g. 'api-test').
    RateLimiter::for('api', fn () => Limit::perMinute(120));

    $user = User::factory()->create();

    $this->actingAs($user)
         ->getJson('/api/posts')
         ->assertOk();
});
```

Always clear rate limiter state in `beforeEach` when testing rate-limited endpoints to prevent test pollution.
