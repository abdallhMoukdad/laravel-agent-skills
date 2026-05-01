---
name: laravel-auth
description: This skill should be used when the user asks to "add authentication", "protect a route", "create a policy", "check permissions", "set up Sanctum", "add authorization", "create middleware", "use gates", or when implementing auth, authorization, or access control in a Laravel 12 API.
version: 1.0.0
---

# Laravel Auth Skill

## Sanctum for All API Authentication

Always use Sanctum for API authentication. Never roll a custom auth system.

Install and configure Sanctum:

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

### Token Auth (Mobile Apps and Third-Party Clients)

Add `HasApiTokens` to the `User` model for stateless token-based auth:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use HasApiTokens;
}
```

Issue a token on login:

```php
$token = $user->createToken('mobile-app', ['read', 'write']);
return response()->json(['token' => $token->plainTextToken]);
```

Access `->plainTextToken` once, return it to the client, and never store it again server-side. The client stores and sends it as `Authorization: Bearer <token>`.

### SPA Cookie Auth (First-Party SPAs)

For same-domain SPAs, add `EnsureFrontendRequestsAreStateful` to the API middleware group in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->statefulApi();
})
```

The SPA calls `GET /sanctum/csrf-cookie` first, then sends all state-changing requests with the `X-XSRF-TOKEN` header sourced from the `XSRF-TOKEN` cookie.

### Choosing Token vs Cookie

- Stateless clients (mobile, third-party, CLI): token auth
- Same-domain first-party SPAs: cookie/session auth

### Protecting Routes

Apply `auth:sanctum` at the route group level, never per individual route:

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('posts', PostController::class);
    Route::apiResource('orders', OrderController::class);
});
```

---

## Policies for Model-Based Authorization

Create one policy per model. Name policies `{Model}Policy` and place them in `app/Policies/`. Laravel 11+ auto-discovers them — no manual registration required.

```bash
php artisan make:policy PostPolicy --model=Post
```

### The `before()` Super-Admin Bypass

Use `before()` to grant admins unconditional access. Return `true` to allow, `null` to fall through to the specific policy method. Never return `false` from `before()` unless explicitly blocking admins:

```php
public function before(User $user, string $ability): bool|null
{
    if ($user->isAdmin()) {
        return true;
    }

    return null; // continue to specific method
}
```

### Where to Authorize

Follow this strict rule:

| Operation | Where to authorize |
|-----------|-------------------|
| `create`, `store` | Form Request `authorize()` |
| `update` | Form Request `authorize()` |
| `show`, `destroy` | Controller `$this->authorize()` |

Form Request (no model bound yet):

```php
public function authorize(): bool
{
    return $this->user()?->can('create', Post::class) ?? false;
}
```

Controller (model already bound via route model binding):

```php
public function show(Post $post): JsonResponse
{
    $this->authorize('view', $post);

    return response()->json($post);
}

public function destroy(Post $post): JsonResponse
{
    $this->authorize('delete', $post);

    $post->delete();

    return response()->json(null, 204);
}
```

Never authorize inside a Model. Never authorize inside a Service class.

---

## Gates for Non-Model Authorization

Use Gates for feature flags, admin checks, and actions that do not belong to a single model. Define all Gates in `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('access-dashboard', fn(User $user): bool =>
        $user->hasRole('admin') || $user->hasRole('manager')
    );

    Gate::define('export-reports', fn(User $user): bool =>
        $user->subscription_tier === 'enterprise'
    );
}
```

Use Gates in controllers:

```php
Gate::authorize('access-dashboard'); // throws 403 on failure
```

Or check programmatically:

```php
if (Gate::allows('export-reports')) {
    // proceed
}
```

---

## Route-Level Authorization

Apply `->middleware('can:update,post')` only for simple single-policy checks directly on a route. For any logic more complex than a single policy method call, use `$this->authorize()` inside the controller:

```php
// simple — acceptable at route level
Route::put('/posts/{post}', [PostController::class, 'update'])
    ->middleware('can:update,post');

// complex — always move to controller
public function update(UpdatePostRequest $request, Post $post): JsonResponse
{
    $this->authorize('update', $post);
    // ...
}
```

---

## Never Trust Client-Side Authorization

Frontend `can()` checks (Inertia, Blade, or API-served permission lists) are UX conveniences only. Every state-changing operation must be authorized server-side. A hidden button does not prevent a direct API call.

---

## Token Abilities

Scope tokens to limit what a client can do:

```php
$token = $user->createToken('read-only-client', ['read']);
```

Check abilities in controllers or middleware:

```php
if ($request->user()->tokenCan('read')) {
    // allowed
}
```

---

## Revoking Tokens

Revoke the current session token on logout:

```php
$request->user()->currentAccessToken()->delete();
return response()->json(null, 204);
```

Revoke all tokens (force logout everywhere):

```php
$user->tokens()->delete();
```

---

## Token Expiration

Set token lifetime in `config/sanctum.php`:

```php
'expiration' => 60 * 24 * 30, // 30 days in minutes, null = never
```

Schedule pruning of expired tokens in `routes/console.php`:

```php
use Laravel\Sanctum\PersonalAccessToken;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
```

---

## Testing Auth

Use `Sanctum::actingAs()` in feature tests. Never make real HTTP calls with real tokens in tests:

```php
use Laravel\Sanctum\Sanctum;

Sanctum::actingAs($user);
$this->getJson('/api/posts')->assertOk();

// Test ability-scoped token
Sanctum::actingAs($user, ['update-post']);
$this->putJson('/api/posts/1', [...])->assertOk();
```

---

## Additional Resources

- [`references/sanctum.md`](references/sanctum.md) — Full Sanctum reference: token lifecycle, SPA auth flow, expiration, testing, multiple guards
- [`references/gates-policies.md`](references/gates-policies.md) — Full Gates and Policies reference: all 7 policy methods, `before`/`after` hooks, policy responses, programmatic checks, testing
