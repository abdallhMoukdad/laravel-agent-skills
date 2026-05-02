# Gates and Policies Reference

## Gate Definition

Define Gates in `AppServiceProvider::boot()`. Use Gates for actions that do not map cleanly to a single Eloquent model — feature flags, role checks, cross-model operations:

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('access-admin-panel', fn(User $user): bool =>
            $user->hasRole('admin')
        );

        Gate::define('export-reports', fn(User $user): bool =>
            in_array($user->plan, ['pro', 'enterprise'], strict: true)
        );

        Gate::define('view-audit-log', function (User $user): bool {
            return $user->hasRole('admin') || $user->hasPermission('audit:read');
        });
    }
}
```

---

## Global Super-Admin Override with Gate::before()

`Gate::before()` runs before every Gate check and every Policy method. Return `true` to allow unconditionally, `null` to continue normal evaluation:

```php
Gate::before(function (User $user, string $ability): bool|null {
    if ($user->isSuperAdmin()) {
        return true; // bypasses all gates and policies
    }

    return null; // fall through to gate/policy
});
```

`Gate::after()` runs after every check and can override results:

```php
Gate::after(function (User $user, string $ability, bool|null $result): bool|null {
    if ($user->hasRole('moderator') && str_starts_with($ability, 'view')) {
        return true; // moderators can always view
    }

    return $result; // return original result otherwise
});
```

---

## Programmatic Gate Checks

```php
use Illuminate\Support\Facades\Gate;

// Throws AuthorizationException (HTTP 403) on failure
Gate::authorize('access-admin-panel');

// Returns bool
Gate::allows('access-admin-panel');  // true if allowed
Gate::denies('access-admin-panel');  // true if denied

// Check multiple gates — any or all
Gate::any(['edit-settings', 'manage-users']);   // true if at least one passes
Gate::none(['suspend-account', 'delete-data']); // true if none pass

// Check with arguments
Gate::allows('update', $post); // delegates to PostPolicy::update()

// Check and get a Response object
$response = Gate::inspect('delete', $post);
if ($response->denied()) {
    return response()->json(['message' => $response->message()], 403);
}
```

---

## Policy Class Anatomy

Generate a policy with the Artisan command:

```bash
php artisan make:policy PostPolicy --model=Post
```

A complete policy with all 7 standard methods:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class PostPolicy
{
    /**
     * Super-admin bypass. Return true to allow, null to fall through.
     */
    public function before(User $user, string $ability): bool|null
    {
        if ($user->isAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * Determine if the user can list all posts.
     */
    public function viewAny(User $user): bool
    {
        return true; // any authenticated user can list
    }

    /**
     * Determine if the user can view a single post.
     */
    public function view(User $user, Post $post): bool
    {
        return $post->published || $post->user_id === $user->id;
    }

    /**
     * Determine if the user can create posts.
     */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Determine if the user can update the post.
     */
    public function update(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Determine if the user can delete the post.
     */
    public function delete(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Determine if the user can restore a soft-deleted post.
     */
    public function restore(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;
    }

    /**
     * Determine if the user can permanently delete the post.
     */
    public function forceDelete(User $user, Post $post): bool
    {
        return $user->hasRole('editor') && $user->id === $post->user_id;
    }
}
```

---

## The before() and after() Methods

### before()

- Runs before any policy method.
- Return `true`: grant access immediately, skip the specific method.
- Return `null`: fall through to the specific policy method.
- Never return `false` from `before()` unless the intent is to block a specific group (e.g., banned users).

```php
public function before(User $user, string $ability): bool|null
{
    // Ban check — block before anything else
    if ($user->isBanned()) {
        return false;
    }

    // Admin bypass
    if ($user->isAdmin()) {
        return true;
    }

    return null; // proceed to viewAny(), update(), etc.
}
```

### after()

- Runs after the specific policy method.
- The `$result` parameter holds the outcome from the specific method (`true`, `false`, or `null`).
- Return a new `bool` to override the result; return `$result` to leave it unchanged.

```php
public function after(User $user, string $ability, bool|null $result): bool|null
{
    // Owners always pass, regardless of specific method result
    if ($user->id === $this->ownerId) {
        return true;
    }

    return $result;
}
```

---

## Auto-Discovery (Laravel 11+)

Policies in `app/Policies/` are auto-discovered when they follow the naming convention:

| Model | Policy |
|-------|--------|
| `App\Models\Post` | `App\Policies\PostPolicy` |
| `App\Models\Order` | `App\Policies\OrderPolicy` |
| `App\Models\User` | `App\Policies\UserPolicy` |

No manual registration is needed. `AuthServiceProvider` was removed in Laravel 11 and the `$policies` array no longer exists. Simply place the policy class in `app/Policies/` and Laravel discovers it automatically.

If a custom mapping is necessary, register explicitly in `AppServiceProvider::boot()`:

```php
Gate::policy(Post::class, PostPolicy::class);
```

---

## Authorizing in Controllers

Use `Gate::authorize()` when the model is already resolved by route model binding (`show`, `update`, `destroy`).

> Laravel 11+ removed `AuthorizesRequests` from the default `App\Http\Controllers\Controller`, so `$this->authorize(...)` no longer works out of the box. Use `Gate::authorize(...)` instead, or add `use AuthorizesRequests;` to your base controller if you prefer the trait form.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class PostController extends Controller
{
    public function show(Post $post): JsonResponse
    {
        Gate::authorize('view', $post);

        return response()->json($post);
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        Gate::authorize('update', $post);

        $post->update($request->validated());

        return response()->json($post);
    }

    public function destroy(Post $post): JsonResponse
    {
        Gate::authorize('delete', $post);

        $post->delete();

        return response()->json(null, 204);
    }
}
```

`Gate::authorize()` throws `Illuminate\Auth\Access\AuthorizationException`, which Laravel converts to a 403 JSON response automatically.

---

## Authorizing in Form Requests

Use Form Request `authorize()` only for `create` and `store` operations where no model is bound yet:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;

final class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body'  => ['required', 'string'],
        ];
    }
}
```

For `update`, the model is already resolved by route model binding — authorize in the controller with `Gate::authorize('update', $post)` instead.

---

## Authorizing in API Resources

Conditionally expose actions or links based on the authenticated user's abilities:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'title' => $this->title,
            'body'  => $this->body,

            // Expose delete URL only when the user is allowed to delete
            'links' => [
                'delete' => $this->when(
                    $request->user()?->can('delete', $this->resource),
                    fn() => route('posts.destroy', $this->resource)
                ),
            ],
        ];
    }
}
```

---

## Policy Responses

Return a `Response` object from any policy method for custom error messages:

```php
use Illuminate\Auth\Access\Response;

public function update(User $user, Post $post): Response
{
    if ($user->id !== $post->user_id) {
        return Response::deny('Only the post author can edit this post.');
    }

    return Response::allow();
}
```

Return a 404 instead of a 403 to hide resource existence from unauthorized users:

```php
public function view(User $user, Post $post): Response
{
    if (! $post->published && $user->id !== $post->user_id) {
        return Response::denyAsNotFound(); // returns 404
    }

    return Response::allow();
}
```

Custom HTTP status codes:

```php
return Response::deny('Subscription required.', 402);
```

---

## Testing Policies and Gates

Test authorization behavior via HTTP feature tests rather than unit-testing policy methods in isolation. This validates the full middleware stack, route model binding, and policy resolution:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PostAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_delete_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->for($user)->create();

        Sanctum::actingAs($user);

        $this->deleteJson("/api/posts/{$post->id}")
             ->assertNoContent();
    }

    public function test_non_owner_cannot_delete_post(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $post  = Post::factory()->for($owner)->create();

        Sanctum::actingAs($other);

        $this->deleteJson("/api/posts/{$post->id}")
             ->assertForbidden();
    }

    public function test_admin_can_delete_any_post(): void
    {
        $admin = User::factory()->admin()->create();
        $post  = Post::factory()->create();

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/posts/{$post->id}")
             ->assertNoContent();
    }

    public function test_gate_blocks_non_admin_from_admin_panel(): void
    {
        $user = User::factory()->create(); // no admin role

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/dashboard')
             ->assertForbidden();
    }
}
```

For unit-testing a policy method directly:

```php
public function test_policy_denies_update_for_non_owner(): void
{
    $owner  = User::factory()->make(['id' => 1]);
    $other  = User::factory()->make(['id' => 2]);
    $post   = Post::factory()->make(['user_id' => 1]);
    $policy = new \App\Policies\PostPolicy();

    $this->assertTrue($policy->update($owner, $post));
    $this->assertFalse($policy->update($other, $post));
}
```
