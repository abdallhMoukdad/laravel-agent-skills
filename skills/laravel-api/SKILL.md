---
name: laravel-api
description: This skill should be used when the user asks to "create a controller", "add a route", "write a form request", "create an API resource", "build an endpoint", "validate a request", "return a JSON response", or when building REST API endpoints in Laravel 12.
version: 1.0.0
---

## Form Requests

Never use `$request->validate()` inside a controller. Extract every validation rule set to a dedicated Form Request class.

```bash
php artisan make:request StorePostRequest
```

Always access validated data via `$request->validated()`. Never use `$request->all()` or `$request->input()` to pass data downstream.

```php
public function store(StorePostRequest $request): JsonResponse
{
    $post = $this->postService->create($request->validated());

    return PostResource::make($post)->response()->setStatusCode(201);
}
```

The `authorize()` method must perform a real policy check, not just `return true`.

```php
public function authorize(): bool
{
    return $this->user()->can('create', Post::class);
}
```

Use `prepareForValidation()` to normalize input before rules run: trim strings, cast types, or derive computed fields.

Use the `after()` hook for cross-field validation that cannot be expressed in rule syntax.

Prefer custom rule classes (`php artisan make:rule`) for logic reused across multiple requests. Use inline closures only for single-use validation.

## API Resources

Never return `$model->toArray()`, raw arrays, or `response()->json($model)`. Always wrap responses in a dedicated API Resource class.

```bash
php artisan make:resource PostResource
```

Use `$this->whenLoaded()` for every relationship inside `toArray()`. Never access a relationship directly — it triggers N+1 queries and fails when the relation is not eager-loaded.

```php
public function toArray(Request $request): array
{
    return [
        'id'       => $this->id,
        'title'    => $this->title,
        'author'   => UserResource::make($this->whenLoaded('author')),
        'comments' => CommentResource::collection($this->whenLoaded('comments')),
    ];
}
```

Use `$this->when($condition, $value)` to include attributes conditionally — such as admin-only fields.

```php
'admin_notes' => $this->when(
    $request->user()?->isAdmin(),
    $this->admin_notes,
),
```

Use `UserResource::collection($paginator)` to return paginated collections. Laravel automatically appends `links` and `meta` pagination keys.

Never call `JsonResource::withoutWrapping()` globally. The `data` wrapper is part of the consistent JSON contract. Disable it only at the individual resource level and only when integrating with a third-party client that requires a specific shape.

## Controllers

Controllers must be thin: receive a validated Form Request, delegate to one service or action class, and return a Resource. No other logic belongs in a controller.

No database queries in controllers. No business logic. No if-chains that decide outcomes based on model state.

```php
final class PostController
{
    public function store(StorePostRequest $request, PostService $service): JsonResponse
    {
        $post = $service->create($request->validated());

        return PostResource::make($post)->response()->setStatusCode(201);
    }

    public function destroy(Post $post, PostService $service): Response
    {
        $service->delete($post);

        return response()->noContent();
    }
}
```

Use single-action invokable controllers for operations that don't map to a standard CRUD resource.

```bash
php artisan make:controller Actions/PublishPostController --invokable
```

```php
final class PublishPostController
{
    public function __invoke(Post $post, PublishPostAction $action): JsonResponse
    {
        $action->execute($post);

        return PostResource::make($post->refresh())->response();
    }
}
```

## Routing

Always register API routes with `Route::apiResource()`, not `Route::resource()`. The `apiResource` variant excludes the `create` and `edit` HTML form routes.

```php
Route::apiResource('posts', PostController::class);
```

This generates five routes: `index`, `show`, `store`, `update`, `destroy`.

Always group routes with `prefix`, `middleware`, and `name` applied together.

```php
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('v1.')
    ->group(base_path('routes/api/v1.php'));
```

Always name every route. Never reference routes by URL string in application code.

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

Always use implicit route model binding. Never call `Model::findOrFail($id)` inside a handler.

```php
// Correct
public function show(Post $post): JsonResponse
{
    return PostResource::make($post)->response();
}

// Wrong — never do this
public function show(int $id): JsonResponse
{
    $post = Post::findOrFail($id);
    return PostResource::make($post)->response();
}
```

Override `getRouteKeyName()` on the model to bind by a slug or UUID instead of the primary key.

```php
public function getRouteKeyName(): string
{
    return 'slug';
}
```

For nested resources, Laravel automatically scopes the child binding to the parent. Declare the child in the route signature: `Route::apiResource('users.posts', UserPostController::class)`.

## Response Conventions

Use a consistent JSON structure across all endpoints: `{ "data": ..., "message": "..." }`. API Resources enforce the `data` wrapper automatically.

Standard HTTP status codes:

| Scenario | Code |
|---|---|
| GET, PUT, PATCH success | 200 |
| POST that creates a resource | 201 |
| DELETE (no body) | 204 |
| Validation failure | 422 |
| Unauthenticated | 401 |
| Forbidden | 403 |
| Not found | 404 |

Return 204 with no body for delete operations.

```php
return response()->noContent(); // 204
```

Return 201 with the created resource for store operations.

```php
return PostResource::make($post)->response()->setStatusCode(201);
```

## Pagination

Never use `->get()` on list endpoints. Always paginate.

```php
// Simple pagination with total count
$posts = Post::with('author')->paginate(20);
return PostResource::collection($posts);

// Cursor pagination for large datasets or infinite scroll
$posts = Post::with('author')->cursorPaginate(20);
return PostResource::collection($posts);
```

Prefer `->cursorPaginate()` for large datasets or infinite scroll interfaces — it is more performant because it does not execute a `COUNT(*)` query. Use `->paginate()` when the total record count must appear in the response.

Never hard-code a page size. Accept `per_page` as a validated query parameter with a capped maximum.

```php
$perPage = min((int) $request->query('per_page', 20), 100);
$posts   = Post::paginate($perPage);
```

## Additional Resources

- `references/form-requests.md` — Complete Form Request class anatomy, `authorize()` policy checks, `prepareForValidation()`, `after()` hook, custom rule classes, conditional rules, array validation, and testing.
- `references/api-resources.md` — Resource class anatomy, `whenLoaded()`, conditional attributes, resource collections, pagination wrapping, nested resources, `additional()`, and response customization.
- `references/controllers-routing.md` — The 7 resource methods, single-action controllers, route grouping, named routes, route model binding scoping, custom binding, API versioning, nested resources, and rate limiting.
