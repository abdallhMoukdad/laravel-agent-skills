---
name: laravel-security
description: This skill should be used when the user asks to "secure a Laravel app", "prevent SQL injection", "set up rate limiting", "configure CORS", "protect against mass assignment", "set up Sanctum token auth", "validate input securely", "hide sensitive fields", or when working with authorization, input validation, or API security in Laravel 12.
version: 1.0.0
---

## Mass Assignment

Always define `$fillable` explicitly on every model. Never use `$guarded = []` in production — it exposes every column to mass assignment including `is_admin`, `role`, and `email_verified_at`.

```php
// Correct — explicit whitelist
class User extends Model
{
    protected $fillable = ['name', 'email', 'password'];
}

// Wrong — exposes ALL columns, including is_admin, role, stripe_id
class User extends Model
{
    protected $guarded = [];
}
```

Use `$fillable` as a whitelist, never `$guarded` as a blacklist. When adding a new column to a table, it is not in scope for mass assignment until explicitly added to `$fillable`.

Use `forceFill()` only for trusted internal operations (seeders, admin scripts). Never pass user-controlled data to `forceFill()`.

---

## Input Validation

Always validate in the Form Request `rules()` method before the controller runs. Never call `$request->all()` or `$request->input()` without prior validation.

```bash
php artisan make:request StorePostRequest
```

```php
class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Post::class);
    }

    public function rules(): array
    {
        return [
            'title'      => ['required', 'string', 'max:255'],
            'body'       => ['required', 'string', 'max:10000'],
            'status'     => ['required', 'in:draft,published'],
            'tags'       => ['nullable', 'array', 'max:10'],
            'tags.*'     => ['string', 'max:50'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
        ];
    }
}
```

Validate shape, type, and range — not just presence. Never trust that a client sends the correct type. Use `integer`, `string`, `boolean`, `array`, `in:`, `exists:`, and `min:`/`max:` rules accordingly.

Never sanitize data before storing it. Validate the expected shape; escape on output.

---

## SQL Injection Prevention

Never interpolate user input into raw SQL strings. Use Eloquent or the `DB::` query builder with bound parameters.

```php
// Wrong — directly injectable
$users = DB::select("SELECT * FROM users WHERE email = '$email'");

// Correct — Eloquent with bound parameter
$user = User::where('email', $email)->first();

// Correct — query builder with binding
$user = DB::table('users')->where('email', $email)->first();
```

When `DB::raw()` or `whereRaw()` is unavoidable, always bind values via the second argument. Never concatenate user input into the raw string.

```php
// Wrong
$results = DB::select(DB::raw("SELECT * FROM posts WHERE title LIKE '%$search%'"));

// Correct — bind the value
$results = DB::select(
    DB::raw('SELECT * FROM posts WHERE title LIKE :search'),
    ['search' => '%' . $search . '%']
);

// Correct — whereRaw with bindings
Post::whereRaw('LOWER(title) LIKE ?', ['%' . strtolower($search) . '%'])->get();
```

---

## Rate Limiting

Define named rate limiters in `AppServiceProvider::boot()` using `RateLimiter::for()`. Never rely on the default `throttle:60,1` — define explicit limits per route group.

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('api', function (Request $request): Limit {
        return $request->user()
            ? Limit::perMinute(120)->by($request->user()->id)
            : Limit::perMinute(20)->by($request->ip());
    });

    RateLimiter::for('login', function (Request $request): Limit {
        return Limit::perMinute(5)->by($request->ip());
    });
}
```

Apply the `throttle:<name>` middleware on route groups:

```php
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::apiResource('posts', PostController::class);
});

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');
```

Key per user ID when authenticated, fall back to IP for guests. Set tighter limits on sensitive endpoints (login, password reset, token creation).

---

## CORS

Configure CORS in `config/cors.php`. Never set `allowed_origins: ['*']` in production.

```php
// config/cors.php
return [
    'paths'               => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_origins'     => ['https://app.example.com'],
    'allowed_methods'     => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_headers'     => ['Content-Type', 'Authorization', 'X-Requested-With'],
    'exposed_headers'     => [],
    'max_age'             => 0,
    'supports_credentials' => false,
];
```

Set `supports_credentials: true` only when cookie-based Sanctum SPA auth is used. Never combine `supports_credentials: true` with `allowed_origins: ['*']` — browsers reject that combination and it is a security misconfiguration.

---

## Sanctum Token Security

Always set a token expiration. Tokens that never expire are permanent credentials.

```php
// config/sanctum.php
'expiration' => 60 * 24 * 7,  // 7 days in minutes; null means no expiration — never use null in production
```

Always revoke tokens on password change and on explicit logout:

```php
// On password change
$user->tokens()->delete();
$user->update(['password' => Hash::make($newPassword)]);

// On logout
$request->user()->currentAccessToken()->delete();

// Revoke all sessions (force logout everywhere)
$request->user()->tokens()->delete();
```

Never store tokens in `localStorage`. Use HttpOnly cookies for SPA auth (Sanctum stateful) so JavaScript cannot access the token. For mobile/SPA token auth, store tokens in secure, encrypted storage — never in `localStorage` or `sessionStorage`.

---

## Sensitive Data

Always define `$hidden` on every model for columns that must never appear in JSON responses.

```php
class User extends Model
{
    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];
}
```

Never return raw database rows from controllers. Always transform through an API Resource.

```php
// Wrong — exposes the full database row with no explicit output contract; bypasses field renaming, relationship control, and versioning.
return response()->json($user);

// Correct — explicit output contract
return new UserResource($user);
```

Never log passwords, tokens, credit card numbers, or any PII. Configure `$dontFlash` in the exception handler (via `->withExceptions()` in `bootstrap/app.php` in Laravel 11+) to prevent sensitive fields from appearing in error reports and session flashes.

---

## Authorization

Always call `authorize()` in the Form Request for unbound resource creation actions. Use `$this->authorize()` in the controller for model-bound actions.

```php
// Form Request authorize() — for create actions
public function authorize(): bool
{
    return $this->user()->hasVerifiedEmail()
        && $this->user()->can('create', Post::class);
}

// Controller — for model-bound actions
public function update(UpdatePostRequest $request, Post $post): PostResource
{
    $this->authorize('update', $post);  // throws 403 if unauthorized

    $post->update($request->validated());

    return new PostResource($post);
}
```

Policies are auto-discovered when the model and policy follow naming conventions (`PostPolicy` for `Post`, located at `app/Policies/PostPolicy.php`). For non-standard locations, register manually in `AuthServiceProvider`. Generate with `php artisan make:policy PostPolicy --model=Post`.

Never skip authorization on endpoints that "feel internal." Every route that mutates data or returns user-scoped data must have an authorization check. Never gate authorization on client-supplied flags in the request body — always derive authorization from the authenticated user's actual permissions.

---

## Artisan Commands Reference

```bash
php artisan make:request StorePostRequest
php artisan make:policy PostPolicy --model=Post
php artisan make:resource UserResource
```

---

## Additional Resources

- `references/mass-assignment.md` — Full explanation of `$fillable` vs `$guarded`, exploit scenarios, safe usage of `create()` / `update()` / `fill()` / `forceFill()`, and recommended defaults for new models.
- `references/rate-limiting.md` — Complete `RateLimiter::for()` configuration, per-user vs per-IP limits, different limits for authenticated vs guest users, non-HTTP contexts, 429 responses with `Retry-After`, and testing rate limiters.
- `references/input-security.md` — SQL injection scenarios and binding prevention, safe and unsafe `DB::raw()` / `whereRaw()` / `selectRaw()` usage, full Form Request validation rules reference, sanitization vs validation distinction, and XSS prevention for API responses.
