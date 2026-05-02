# Controllers and Routing — Reference

## The 7 Resource Controller Methods

`Route::apiResource()` maps HTTP verbs to these controller methods (it omits `create` and `edit`):

| Method    | HTTP Verb | URI                     | Route Name        | Purpose                          |
|-----------|-----------|-------------------------|-------------------|----------------------------------|
| `index`   | GET       | `/posts`                | `posts.index`     | Return a paginated list          |
| `store`   | POST      | `/posts`                | `posts.store`     | Validate and create a resource   |
| `show`    | GET       | `/posts/{post}`         | `posts.show`      | Return a single resource         |
| `update`  | PUT/PATCH | `/posts/{post}`         | `posts.update`    | Validate and update a resource   |
| `destroy` | DELETE    | `/posts/{post}`         | `posts.destroy`   | Delete a resource, return 204    |
| `create`  | —         | excluded by apiResource | —                 | HTML form — not used in APIs     |
| `edit`    | —         | excluded by apiResource | —                 | HTML form — not used in APIs     |

Each method must stay thin: accept a Form Request, call one service or action, return a Resource or `noContent()`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PostController
{
    public function __construct(private readonly PostService $postService) {}

    public function index(Request $request): JsonResponse
    {
        // Pagination, eager-loading, and filtering are encapsulated in the service.
        $posts = $this->postService->paginate($request);

        return PostResource::collection($posts)->response();
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->postService->create($request->validated());

        return PostResource::make($post)->response()->setStatusCode(201);
    }

    public function show(Post $post): JsonResponse
    {
        // Route model binding resolves the model; relationship loading is handled
        // by the service layer or a custom binding resolver — not here.
        return PostResource::make($post)->response();
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        $this->postService->update($post, $request->validated());

        return PostResource::make($post->refresh())->response();
    }

    public function destroy(Post $post): Response
    {
        $this->postService->delete($post);

        return response()->noContent();
    }
}
```

## Single-Action Invokable Controllers

Use single-action controllers for operations that don't map to a CRUD resource: publishing, archiving, verifying, resending, etc.

```bash
php artisan make:controller Actions/PublishPostController --invokable
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Actions;

use App\Actions\PublishPostAction;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\JsonResponse;

final class PublishPostController
{
    public function __invoke(Post $post, PublishPostAction $action): JsonResponse
    {
        $action->execute($post);

        return PostResource::make($post->refresh())->response();
    }
}
```

Register with a verb that reflects the action, not a generic CRUD verb:

```php
Route::post('/posts/{post}/publish', Actions\PublishPostController::class)
    ->name('posts.publish');
```

## Route Grouping

Always apply `prefix`, `middleware`, and `name` together. Never define API routes without a version prefix and auth middleware.

```php
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('v1.')
    ->group(base_path('routes/api/v1.php'));
```

Nest groups for sub-sections of the API:

```php
Route::prefix('v1')->name('v1.')->middleware('auth:sanctum')->group(function (): void {
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function (): void {
        Route::apiResource('users', Admin\UserController::class);
    });

    Route::apiResource('posts', PostController::class);
    Route::apiResource('comments', CommentController::class);
});
```

## Named Routes

Always name every route. Never reference a route by its URL string inside application code. Use `route('name')` helpers everywhere.

```php
Route::post('/posts/{post}/publish', Actions\PublishPostController::class)
    ->name('posts.publish');

// In application code
$url = route('v1.posts.publish', $post);
```

`Route::apiResource()` auto-generates names. Override them individually when the defaults conflict:

```php
Route::apiResource('posts', PostController::class)->names([
    'index'   => 'posts.index',
    'store'   => 'posts.store',
    'show'    => 'posts.show',
    'update'  => 'posts.update',
    'destroy' => 'posts.destroy',
]);
```

## Route Model Binding

### Implicit Binding

Laravel resolves route parameters to model instances automatically. The parameter name must match the model's class name in camelCase.

```php
Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');

public function show(Post $post): JsonResponse
{
    return PostResource::make($post)->response();
}
```

### Scoped Nested Binding

By default, nested route bindings are NOT scoped to the parent — child IDs are looked up globally. Call `->scoped()` (or `->scopeBindings()` on individual routes) to enforce that the child belongs to the parent:

```php
Route::apiResource('users.posts', UserPostController::class)
    ->scoped(['post' => 'slug']); // or just ->scoped()

// Now Laravel verifies that $post->user_id === $user->id.
public function show(User $user, Post $post): JsonResponse
{
    return PostResource::make($post)->response();
}
```

### Custom Route Key

Override `getRouteKeyName()` on the model to bind by slug, UUID, or any other unique column:

```php
public function getRouteKeyName(): string
{
    return 'slug';
}
```

### Explicit Binding

For custom resolution logic, register an explicit binding in `AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Route;

Route::bind('post', fn (string $value): Post => Post::where('slug', $value)->firstOrFail());
```

## API Versioning

Store each version's routes in a dedicated file:

```
routes/
  api/
    v1.php
    v2.php
```

Register in `bootstrap/app.php` using `->withRouting()`:

```php
->withRouting(
    web:    __DIR__ . '/../routes/web.php',
    then:   function (): void {
        Route::middleware('api')
            ->prefix('api/v1')
            ->name('api.v1.')
            ->group(base_path('routes/api/v1.php'));

        Route::middleware('api')
            ->prefix('api/v2')
            ->name('api.v2.')
            ->group(base_path('routes/api/v2.php'));
    },
)
```

Never mutate the existing versioned route file when introducing breaking changes. Create a new version file and duplicate or extend the controllers into a dedicated `v2` namespace.

## Nested Resources with shallow()

`->shallow()` generates non-nested routes for `show`, `update`, and `destroy` — only `index` and `store` remain nested. This avoids redundant parent IDs in URLs where the child ID is already unique.

```php
Route::apiResource('users.posts', UserPostController::class)->shallow();
```

Generated routes:

```
GET    /users/{user}/posts          users.posts.index
POST   /users/{user}/posts          users.posts.store
GET    /posts/{post}                posts.show
PUT    /posts/{post}                posts.update
DELETE /posts/{post}                posts.destroy
```

## Rate Limiting

Define rate limiters in `AppServiceProvider::boot()`:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    RateLimiter::for('api', function (Request $request): Limit {
        return Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip());
    });

    RateLimiter::for('uploads', function (Request $request): Limit {
        return Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip())
            ->response(fn () => response()->json([
                'message' => 'Too many uploads. Try again later.',
            ], 429));
    });
}
```

Apply the rate limiter via `throttle:` middleware on a route group:

```php
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('v1.')
    ->group(base_path('routes/api/v1.php'));
```

Apply a tighter limiter on specific expensive endpoints:

```php
Route::post('/media', [MediaController::class, 'store'])
    ->middleware('throttle:uploads')
    ->name('media.store');
```

## Route::apiResource() vs Route::resource()

Always use `Route::apiResource()` for REST APIs. It registers five routes and excludes the HTML form routes `create` and `edit`.

```php
// Correct for APIs — 5 routes
Route::apiResource('posts', PostController::class);

// Wrong for APIs — 7 routes including create/edit HTML form routes
Route::resource('posts', PostController::class);
```

To expose only a subset of the five routes:

```php
Route::apiResource('posts', PostController::class)->only(['index', 'show']);
Route::apiResource('posts', PostController::class)->except(['destroy']);
```
