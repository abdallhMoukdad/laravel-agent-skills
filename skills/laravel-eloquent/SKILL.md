---
name: laravel-eloquent
description: This skill should be used when the user asks to "create a model", "add a scope", "define a relationship", "write a query", "add an observer", "create a cast", "set up soft deletes", or when working with Eloquent ORM, database models, or query building in Laravel 12.
version: 1.0.0
---

## Models — Core Conventions

Always define `$fillable` explicitly on every model. Never use `$guarded = []`.

```php
protected $fillable = ['name', 'email', 'status'];
```

Always declare `$casts` for dates, booleans, and enums. Never rely on implicit casting.

```php
protected $casts = [
    'created_at'  => 'datetime',
    'updated_at'  => 'datetime',
    'is_active'   => 'boolean',
    'status'      => StatusEnum::class,
    'settings'    => 'array',
];
```

Use `protected $table` when the table name deviates from convention.

```php
protected $table = 'user_profiles';
```

Use PHP 8.2+ `readonly` properties for value objects passed to constructors. Use backed enums for status columns — they integrate directly with `$casts`.

```php
enum StatusEnum: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
    case Pending  = 'pending';
}
```

## Global Scopes

Define every global scope as a dedicated class implementing the `Scope` interface.

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class ActiveScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('is_active', true);
    }
}
```

Register global scopes in the model's `booted()` static method. Never register them in `__construct()`.

```php
protected static function booted(): void
{
    static::addGlobalScope(new ActiveScope());
}
```

Bypass a single global scope with `withoutGlobalScope(ActiveScope::class)`. Remove all global scopes with `withoutGlobalScopes()`.

```php
User::withoutGlobalScope(ActiveScope::class)->get();
User::withoutGlobalScopes()->get();
```

## Local Scopes

Prefix every local scope method with `scope`. Always return `Builder` for chainability.

```php
public function scopeActive(Builder $query): Builder
{
    return $query->where('is_active', true);
}

public function scopeOfType(Builder $query, string $type): Builder
{
    return $query->where('type', $type);
}
```

Apply local scopes without the prefix:

```php
User::active()->ofType('admin')->get();
```

Use local scopes for reusable, named query fragments that appear in more than one place. Dynamic scopes accept parameters — declare types explicitly.

## Accessors and Mutators

Use the `Attribute` return type (available since Laravel 9) for all accessors and mutators.

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

protected function fullName(): Attribute
{
    return Attribute::make(
        get: fn(mixed $value, array $attributes): string =>
            $attributes['first_name'] . ' ' . $attributes['last_name'],
    );
}

protected function password(): Attribute
{
    return Attribute::make(
        set: fn(string $value): string => bcrypt($value),
    );
}
```

Prefer `$casts` over accessors for simple type transformations (dates, booleans, enums). Create custom cast classes implementing `CastsAttributes` for value objects and complex types.

```php
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return new Money((int) $value, $attributes['currency']);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return $value->getAmount();
    }
}
```

Always cast enum columns via `$casts` — never via an accessor.

## Relationships

- `hasOne` — one-to-one ownership (User hasOne Profile).
- `hasMany` — one-to-many ownership (User hasMany Post).
- `belongsTo` — inverse side; always define the foreign key explicitly.
- `belongsToMany` — many-to-many via pivot; add `withPivot()` for extra pivot columns.
- `hasManyThrough` — reach a distant model through an intermediary.
- `morphTo` / `morphMany` / `morphOne` — polymorphic; register a morph map.

```php
public function posts(): HasMany
{
    return $this->hasMany(Post::class, 'author_id');
}

public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class)
        ->withPivot('assigned_at')
        ->withTimestamps();
}
```

Load relationships with `with()` in the query. Never access unloaded relationships inside loops — this causes N+1 queries.

```php
// Correct — eager load before iteration
$users = User::with(['posts', 'posts.comments'])->get();

// Correct — constrained eager load
$users = User::with(['posts' => fn($q) => $q->published()])->get();
```

Use `withCount()` to count related models without loading the full collection.

```php
User::withCount('posts')->get(); // $user->posts_count
User::withSum('orders', 'total')->get(); // $user->orders_sum_total
```

Use `has()`, `doesntHave()`, and `whereHas()` to filter by relationship existence.

```php
User::whereHas('posts', fn($q) => $q->where('published', true))->get();
```

Enable N+1 detection in development. Add to `AppServiceProvider::boot()`:

```php
Model::preventLazyLoading(! app()->isProduction());
```

Use `is()` and `isNot()` for model comparison without additional queries.

```php
if ($post->author->is($currentUser)) { /* ... */ }
```

## Soft Deletes

Apply the `SoftDeletes` trait. Add a `deleted_at` column in the migration.

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected $casts = [
        'deleted_at' => 'datetime',
    ];
}
```

Migration column:

```php
$table->softDeletes(); // adds nullable deleted_at
```

Use `withTrashed()` to include soft-deleted records. Use `onlyTrashed()` to retrieve only soft-deleted records. Use `restore()` to undelete and `forceDelete()` to permanently remove.

## DB:: vs Eloquent

Use `DB::` for:
- Complex multi-table joins where Eloquent relationships add no clarity.
- Raw aggregates (`SUM`, `AVG`) on very large tables where query builder is more explicit.
- Bulk inserts or updates where firing model events is undesirable and performance matters.

Use Eloquent for all other queries. Never mix `DB::` raw expressions and Eloquent in the same logical query chain.

## Additional Resources

- `references/scopes.md` — Complete code examples for global and local scopes, closure-based scopes, bypassing scopes, dynamic scopes, and pitfalls.
- `references/relationships.md` — Full reference for all relationship types, eager loading patterns, pivot tables, aggregates, polymorphic setup, and N+1 detection.
- `references/observers-events.md` — Model event lifecycle, observer class structure, registration methods, `withoutEvents()`, `dispatchesEvents`, and critical pitfalls.
- `references/query-optimization.md` — Column selection, chunking, cursors, lazy collections, `toBase()`, query debugging tools, and `DB::listen()` setup.
