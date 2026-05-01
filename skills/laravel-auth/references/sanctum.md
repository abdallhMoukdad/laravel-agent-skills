# Sanctum Reference

## HasApiTokens Trait

Add `HasApiTokens` to the `User` model. Tokens are stored in the `personal_access_tokens` table, which the migration creates automatically:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}
```

The `personal_access_tokens` table stores: `tokenable_type`, `tokenable_id`, `name`, `token` (SHA-256 hash), `abilities` (JSON), `last_used_at`, `expires_at`.

---

## Creating Tokens

```php
use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

// Full access (wildcard ability)
$token = $user->createToken('my-app-name');

// Scoped abilities
$token = $user->createToken('read-only', ['read']);
$token = $user->createToken('admin-panel', ['read', 'write', 'delete']);

// Access the plaintext token — only available immediately after creation
$plaintext = $token->plainTextToken; // "1|abc123..."
```

`NewAccessToken->plainTextToken` is only accessible on the return value of `createToken()`. After that call returns, the plaintext is gone — only the SHA-256 hash persists in the database. Return `plainTextToken` to the client immediately and never log it.

---

## Token Abilities

Define granular permissions per token:

```php
$token = $user->createToken('mobile', ['posts:read', 'posts:write']);
```

Check abilities on the current request:

```php
// On the User instance (checks current request token)
if ($request->user()->tokenCan('posts:write')) {
    // allowed
}
```

Check inside a Policy or anywhere with the user available:

```php
$user->tokenCan('posts:read');
```

The wildcard ability `'*'` (default when no abilities passed) grants all abilities. `tokenCan()` returns `true` for any string when the token has `'*'`.

---

## Login Flow for API

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $token = $user->createToken($request->device_name ?? 'api');

        return response()->json([
            'token' => $token->plainTextToken,
            'user'  => $user,
        ]);
    }
}
```

`LoginRequest` validates `email`, `password`, and optional `device_name`.

---

## Logout Flow

Invalidate only the current token (single device logout):

```php
public function logout(Request $request): JsonResponse
{
    $request->user()->currentAccessToken()->delete();

    return response()->json(null, 204);
}
```

Invalidate all tokens (logout from every device):

```php
public function logoutEverywhere(Request $request): JsonResponse
{
    $request->user()->tokens()->delete();

    return response()->json(null, 204);
}
```

---

## Revoking Specific Tokens

List and revoke by ID for a "sessions" management screen:

```php
// List all tokens for a user
$tokens = $request->user()->tokens;

// Revoke a specific token by ID
$request->user()->tokens()->where('id', $tokenId)->delete();
```

---

## Token Expiration

In `config/sanctum.php`:

```php
'expiration' => env('SANCTUM_TOKEN_EXPIRY', null),
// null  = tokens never expire
// 1440  = tokens expire after 24 hours (value is in minutes)
// 43200 = tokens expire after 30 days
```

Prune expired tokens on a schedule in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
```

The scheduler removes rows from `personal_access_tokens` where `expires_at` is in the past. Expired tokens still return 401 even before pruning, because Sanctum checks `expires_at` on each request.

---

## SPA Cookie Authentication

Cookie-based auth for same-domain first-party SPAs uses standard Laravel sessions. No tokens are issued.

### Configure the Middleware

In `bootstrap/app.php` (Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->statefulApi();
})
```

This adds `EnsureFrontendRequestsAreStateful` to the `api` middleware group.

### Configure Trusted Origins

In `config/sanctum.php`:

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', implode(',', [
    'localhost',
    'localhost:3000',
    '127.0.0.1',
    '127.0.0.1:8000',
    parse_url(config('app.url'), PHP_URL_HOST),
]))),
```

### SPA Auth Flow

1. SPA calls `GET /sanctum/csrf-cookie` — Sanctum sets the `XSRF-TOKEN` cookie.
2. SPA sends `POST /login` with credentials and `X-XSRF-TOKEN: <value-from-cookie>` header.
3. Laravel authenticates, starts a session, and sets `laravel_session` cookie.
4. All subsequent requests from the SPA carry the session cookie automatically (same-origin).

The `X-XSRF-TOKEN` header must match the `XSRF-TOKEN` cookie on every state-changing request (POST, PUT, PATCH, DELETE).

---

## Multiple Guards

When the application has multiple user types (e.g., `User` and `Admin`), configure separate guards:

In `config/auth.php`:

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'sanctum' => ['driver' => 'sanctum', 'provider' => 'users'],
    'admin' => ['driver' => 'sanctum', 'provider' => 'admins'],
],

'providers' => [
    'users'  => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'admins' => ['driver' => 'eloquent', 'model' => App\Models\Admin::class],
],
```

Protect admin routes:

```php
Route::middleware('auth:admin')->prefix('admin')->group(function () {
    Route::apiResource('users', AdminUserController::class);
});
```

The `Admin` model also uses `HasApiTokens`. Tokens issued to admins are stored in the same `personal_access_tokens` table, distinguished by `tokenable_type = App\Models\Admin`.

---

## Testing with Sanctum

Use `Sanctum::actingAs()` in feature tests. This bypasses token hashing and HTTP overhead entirely.

### Basic Authenticated Request

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PostTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_posts(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/posts')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title']]]);
    }
}
```

### Ability-Scoped Token Test

```php
public function test_read_only_token_cannot_create_post(): void
{
    $user = User::factory()->create();

    Sanctum::actingAs($user, ['posts:read']); // read-only token

    $this->postJson('/api/posts', ['title' => 'New Post'])
        ->assertForbidden();
}

public function test_write_token_can_create_post(): void
{
    $user = User::factory()->create();

    Sanctum::actingAs($user, ['posts:write']);

    $this->postJson('/api/posts', ['title' => 'New Post', 'body' => '...'])
        ->assertCreated();
}
```

### Testing Login Endpoint

```php
public function test_user_can_login_and_receive_token(): void
{
    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $response = $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'secret',
    ]);

    $response->assertOk()
             ->assertJsonStructure(['token', 'user']);
}

public function test_invalid_credentials_return_401(): void
{
    $user = User::factory()->create();

    $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'wrong-password',
    ])->assertUnauthorized();
}
```
