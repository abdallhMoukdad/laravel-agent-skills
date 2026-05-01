# Eloquent Relationships Reference

## Relationship Types

| Method | When to use |
|---|---|
| `hasOne` | One-to-one ownership; this model owns one of the other (User → Profile). |
| `hasMany` | One-to-many ownership; this model owns many (User → Post). |
| `belongsTo` | Inverse side of `hasOne` or `hasMany`; holds the foreign key (Post → User). |
| `belongsToMany` | Many-to-many via pivot table (User ↔ Role). |
| `hasManyThrough` | Reach a distant model through an intermediary (Country → Post through User). |
| `hasOneThrough` | Reach a single distant model through an intermediary (Supplier → AccountHistory through Account). |
| `morphOne` | Polymorphic one-to-one (User morphOne Image). |
| `morphMany` | Polymorphic one-to-many (Post morphMany Comment). |
| `morphTo` | Inverse of any `morph*` relationship (Comment → commentable). |
| `morphToMany` | Polymorphic many-to-many (Post morphToMany Tag). |
| `morphedByMany` | Inverse of `morphToMany` (Tag morphedByMany Post). |

### Basic Definitions

```php
use Illuminate\Database\Eloquent\Relations\{HasOne, HasMany, BelongsTo, BelongsToMany};

// Always declare the foreign key explicitly to avoid surprises
public function profile(): HasOne
{
    return $this->hasOne(Profile::class, 'user_id');
}

public function posts(): HasMany
{
    return $this->hasMany(Post::class, 'author_id');
}

public function author(): BelongsTo
{
    return $this->belongsTo(User::class, 'author_id');
}

public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
        ->withPivot('assigned_at', 'assigned_by')
        ->withTimestamps();
}
```

## Eager Loading

### `with()` — Eager Load at Query Time

```php
// Single relationship
$posts = Post::with('author')->get();

// Nested relationships
$users = User::with('posts.comments.author')->get();

// Multiple relationships
$users = User::with(['posts', 'profile', 'roles'])->get();
```

### `load()` — Lazy Eager Load on Already-Retrieved Models

```php
$users = User::all();
$users->load('posts'); // fires one additional query
$users->load(['posts', 'posts.comments']);
```

### Constraining Eager Loads with Closures

```php
$users = User::with([
    'posts' => function ($query): void {
        $query->where('published', true)->orderByDesc('created_at');
    },
    'posts.comments' => fn($q) => $q->where('approved', true),
])->get();
```

## Aggregate Sub-queries

Use `withCount()`, `withSum()`, `withAvg()`, `withMin()`, `withMax()` to compute aggregates without loading the full collection.

```php
$users = User::withCount('posts')->get();
// Access as: $user->posts_count

$users = User::withSum('orders', 'total')->get();
// Access as: $user->orders_sum_total

$users = User::withAvg('reviews', 'rating')->get();
// Access as: $user->reviews_avg_rating

// Constrained aggregate
$users = User::withCount([
    'posts as published_posts_count' => fn($q) => $q->where('published', true),
])->get();
```

## Filtering by Relationship Existence

```php
// Users who have at least one post
User::has('posts')->get();

// Users who have three or more posts
User::has('posts', '>=', 3)->get();

// Users who have no posts
User::doesntHave('posts')->get();

// Users with at least one published post
User::whereHas('posts', fn($q) => $q->where('published', true))->get();

// Nested: users with posts that have approved comments
User::whereHas('posts.comments', fn($q) => $q->where('approved', true))->get();

// Users with no published posts
User::whereDoesntHave('posts', fn($q) => $q->where('published', true))->get();
```

## Pivot Tables

```php
public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class)
        ->withPivot('assigned_at', 'notes')
        ->wherePivot('active', true)
        ->as('assignment'); // rename pivot accessor
}
```

Access pivot data:

```php
foreach ($user->roles as $role) {
    echo $role->assignment->assigned_at;
    echo $role->assignment->notes;
}
```

Filter by pivot:

```php
$user->roles()->wherePivot('active', true)->get();
$user->roles()->wherePivotBetween('assigned_at', [$start, $end])->get();
```

Sync, attach, and detach:

```php
$user->roles()->sync([1, 2, 3]);
$user->roles()->attach(4, ['assigned_at' => now()]);
$user->roles()->detach(4);
$user->roles()->syncWithoutDetaching([5, 6]);
```

## `is()` and `isNot()` — Comparison Without Extra Queries

```php
if ($post->author->is($currentUser)) {
    // No extra query; compares primary keys
}

if ($comment->author->isNot($post->author)) {
    // Notify the commenter
}
```

## N+1 Detection Setup

Add to `App\Providers\AppServiceProvider::boot()`:

```php
use Illuminate\Database\Eloquent\Model;

public function boot(): void
{
    Model::preventLazyLoading(! app()->isProduction());
}
```

This throws a `LazyLoadingViolationException` in all non-production environments whenever an unloaded relationship is accessed on a model instance.

## Polymorphic Relationships

### Definition

```php
// Comment morphs to Post or Video
class Comment extends Model
{
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class Post extends Model
{
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
```

### Morph Map Registration

Always register a morph map to decouple class names from the database. Add to `AppServiceProvider::boot()`.

```php
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::enforceMorphMap([
    'post'    => \App\Models\Post::class,
    'video'   => \App\Models\Video::class,
    'comment' => \App\Models\Comment::class,
]);
```

`enforceMorphMap` throws an exception when an unmapped morph type is encountered, preventing silent data corruption from class renames.

### Polymorphic Eager Loading

```php
$comments = Comment::with('commentable')->get();

foreach ($comments as $comment) {
    // $comment->commentable is a Post or Video instance
    echo $comment->commentable->title;
}
```

## Relationship Tips

- Declare return types on all relationship methods for IDE support and self-documentation.
- Always define both sides of a relationship (e.g., `hasMany` and `belongsTo`) even if one side is currently unused.
- Use `firstOrCreate` / `firstOrNew` / `updateOrCreate` on relationships to avoid race conditions:
  ```php
  $user->profile()->updateOrCreate([], ['bio' => $bio]);
  ```
- Use `saveMany()` and `createMany()` for inserting multiple related models in one call.
