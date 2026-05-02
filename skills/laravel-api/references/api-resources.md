# API Resources — Reference

## Basic Resource Class Anatomy

Generate a Resource class with:

```bash
php artisan make:resource PostResource
```

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
            'id'         => $this->id,
            'title'      => $this->title,
            'body'       => $this->body,
            'status'     => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
```

Return a single resource from a controller:

```php
return PostResource::make($post)->response()->setStatusCode(201);
// or for 200
return PostResource::make($post);
```

## $this->when() — Conditional Attributes

Include a field only when a condition is true:

```php
'admin_notes' => $this->when(
    $request->user()?->isAdmin(),
    $this->admin_notes,
),
```

Include a field with a default fallback:

```php
'score' => $this->when(
    $this->score !== null,
    $this->score,
    0,
),
```

Use `$this->mergeWhen()` to conditionally merge multiple keys at once. Place the call at a numeric (un-keyed) array position — Laravel's resource `filter()` detects the `MergeValue` and merges automatically. Do NOT spread it with `...`; `MergeValue` is not Traversable and spreading throws a fatal error.

```php
return [
    'id'    => $this->id,
    'title' => $this->title,
    $this->mergeWhen($request->user()?->isAdmin(), [
        'admin_notes' => $this->admin_notes,
        'internal_id' => $this->internal_id,
        'flagged'     => $this->flagged,
    ]),
    'created_at' => $this->created_at,
];
```

## $this->whenLoaded() — Safe Relationship Access

Never access a relationship directly inside `toArray()`. Use `whenLoaded()` so a relationship is only serialized when the controller has eager-loaded it. This prevents accidental lazy loads (and with `Model::preventLazyLoading()` enabled, prevents `LazyLoadingViolationException`). Avoiding N+1 queries themselves is the controller's job — call `with(...)` there.

```php
public function toArray(Request $request): array
{
    return [
        'id'       => $this->id,
        'title'    => $this->title,
        'author'   => UserResource::make($this->whenLoaded('author')),
        'comments' => CommentResource::collection($this->whenLoaded('comments')),
        'tags'     => TagResource::collection($this->whenLoaded('tags')),
    ];
}
```

The controller is responsible for eager-loading:

```php
$post = Post::with(['author', 'comments', 'tags'])->findOrFail($id);

return PostResource::make($post);
```

## $this->whenHas() — Attribute Presence Check

Include an attribute only if it was set on the model (i.e., was selected in the query):

```php
'summary' => $this->whenHas('summary'),
```

This is useful when some queries select all columns and others select a subset.

## Resource Collections and Pagination

Pass a collection or paginator to `::collection()`:

```php
$posts = Post::with('author')->paginate(20);

return PostResource::collection($posts);
```

When `$posts` is a `LengthAwarePaginator`, Laravel automatically appends `links` and `meta` pagination keys to the response alongside `data`.

```json
{
  "data": [...],
  "links": {
    "first": "https://example.com/api/posts?page=1",
    "last":  "https://example.com/api/posts?page=5",
    "prev":  null,
    "next":  "https://example.com/api/posts?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 20,
    "to": 20,
    "total": 98
  }
}
```

## Custom ResourceCollection

Create a custom collection class when extra top-level keys are required or the collection shape needs modification:

```bash
php artisan make:resource PostCollection
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class PostCollection extends ResourceCollection
{
    public $collects = PostResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data'  => $this->collection,
            'stats' => [
                'total_published' => $this->collection->where('status', 'published')->count(),
            ],
        ];
    }
}
```

Use in a controller:

```php
return new PostCollection(Post::with('author')->paginate(20));
```

## $this->additional() — Extra Top-Level Data

Attach extra top-level keys to a resource response without a custom collection class:

```php
return PostResource::make($post)
    ->additional([
        'message' => 'Post created successfully.',
        'meta'    => [
            'server_time' => now()->toIso8601String(),
        ],
    ]);
```

## Nested Resources

Always use a dedicated Resource class for nested relationships. Never call `$this->relation->toArray()` inline.

```php
// Correct
public function toArray(Request $request): array
{
    return [
        'id'     => $this->id,
        'author' => UserResource::make($this->whenLoaded('author')),
    ];
}

// Wrong — never do this
public function toArray(Request $request): array
{
    return [
        'id'     => $this->id,
        'author' => $this->author->toArray(),  // triggers lazy load, bypasses Resource
    ];
}
```

## Wrapping — JsonResource::withoutWrapping()

By default, all Resources wrap their output in a `data` key. This is the expected behavior and provides a consistent JSON contract.

`JsonResource::withoutWrapping()` is a static method that mutates global state (`static::$wrap = null`). Calling it on a specific resource class disables wrapping for every response from that class for the rest of the request lifecycle.

```php
// Disables the 'data' wrapping globally for this resource class:
PostResource::withoutWrapping();
```

There is no per-instance way to disable wrapping. For a single response, build a custom shape with `additional()` or override `toResponse()`.

## Customizing the HTTP Response

Set a custom status code:

```php
return PostResource::make($post)->response()->setStatusCode(201);
```

Add response headers:

```php
return PostResource::make($post)
    ->response()
    ->setStatusCode(201)
    ->header('X-Post-Id', $post->id);
```

Use `withResponse()` as a callback inside the Resource class itself to modify the response for every usage of that resource:

```php
public function withResponse(Request $request, \Illuminate\Http\JsonResponse $response): void
{
    $response->header('X-Resource-Version', '1');
}
```

## Full Example: Resource with All Patterns

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
            'id'          => $this->id,
            'title'       => $this->title,
            'slug'        => $this->slug,
            'body'        => $this->body,
            'status'      => $this->status,

            // Conditional attribute — admin only
            'admin_notes' => $this->when(
                $request->user()?->isAdmin(),
                $this->admin_notes,
            ),

            // Conditional merge — placed at a numeric position; do NOT spread
            $this->mergeWhen($request->user()?->isAdmin(), [
                'internal_flags' => $this->internal_flags,
                'flagged'        => $this->flagged,
            ]),

            // Safe relationship access
            'author'   => UserResource::make($this->whenLoaded('author')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'tags'     => TagResource::collection($this->whenLoaded('tags')),

            // Attribute presence check
            'summary'  => $this->whenHas('summary'),

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
```
