# Eloquent Scopes Reference

## Global Scope — Full Class Example

Define every global scope as a dedicated class implementing `Scope`. Place scope classes in `app/Models/Scopes/`.

```php
<?php

declare(strict_types=1);

namespace App\Models\Scopes;

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

Register in the model's `booted()` static method — never in `__construct()`.

```php
<?php

namespace App\Models;

use App\Models\Scopes\ActiveScope;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new ActiveScope());
    }
}
```

## Anonymous (Closure) Global Scope

Use a closure for simple, one-off global constraints that don't warrant a dedicated class. Provide a string name as the first argument so it can be referenced for removal.

```php
protected static function booted(): void
{
    static::addGlobalScope('recent', function (Builder $builder): void {
        $builder->where('created_at', '>=', now()->subDays(30));
    });
}
```

## Multiple Global Scopes on One Model

Register multiple global scopes independently — each in its own `addGlobalScope()` call.

```php
protected static function booted(): void
{
    static::addGlobalScope(new ActiveScope());
    static::addGlobalScope(new TenantScope());
    static::addGlobalScope('recent', fn(Builder $b) => $b->where('created_at', '>=', now()->subYear()));
}
```

## Bypassing Global Scopes

Remove a specific global scope for a single query:

```php
User::withoutGlobalScope(ActiveScope::class)->get();

// By string name (closure-based scope)
User::withoutGlobalScope('recent')->get();
```

Remove all global scopes for a single query:

```php
User::withoutGlobalScopes()->get();
```

Remove multiple specific scopes:

```php
User::withoutGlobalScopes([ActiveScope::class, TenantScope::class])->get();
```

## Local Scope — With Parameters

Prefix all local scope methods with `scope`. Always return `Builder`.

```php
public function scopeActive(Builder $query): Builder
{
    return $query->where('is_active', true);
}

public function scopeOfType(Builder $query, string $type): Builder
{
    return $query->where('type', $type);
}

public function scopeCreatedBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
{
    return $query->whereBetween('created_at', [$from, $to]);
}
```

Invoke without the `scope` prefix, chaining as needed:

```php
User::active()
    ->ofType('admin')
    ->createdBetween(now()->subMonth(), now())
    ->get();
```

### `#[Scope]` Attribute (Laravel 11+)

Laravel 11+ also accepts the `#[\Illuminate\Database\Eloquent\Attributes\Scope]` attribute as an alternative to the `scope` prefix:

```php
use Illuminate\Database\Eloquent\Attributes\Scope;

#[Scope]
public function active(Builder $query): Builder
{
    return $query->where('is_active', true);
}
```

Both styles work — the prefix form remains canonical.

## Dynamic Local Scopes

Accept variable arguments using variadic parameters or arrays for flexible filtering.

```php
public function scopeOfStatus(Builder $query, StatusEnum ...$statuses): Builder
{
    return $query->whereIn('status', array_column($statuses, 'value'));
}
```

```php
User::ofStatus(StatusEnum::Active, StatusEnum::Pending)->get();
```

## Pitfalls

### Global Scope Breaks `create()`

Global scopes apply to `SELECT` queries. They do not filter `INSERT`. However, if a global scope is poorly written and modifies the query in an unexpected way — such as adding a `JOIN` — it can break model creation. Always test `Model::create()` after adding a global scope.

### Forgetting to Return `$query` from Local Scope

A local scope that does not return `Builder` silently breaks chaining and returns `null` instead of a query.

```php
// Wrong — breaks chaining
public function scopeActive(Builder $query): void
{
    $query->where('is_active', true); // missing return
}

// Correct
public function scopeActive(Builder $query): Builder
{
    return $query->where('is_active', true);
}
```

### Scope Applied to Eager Loaded Queries

Global scopes apply to relationship queries triggered by `with()`. If a related model has a global scope, it also constrains the eager-loaded results.

```php
// ActiveScope on Post also filters posts in this eager load
$users = User::with('posts')->get();

// Bypass for the relationship only
$users = User::with(['posts' => fn($q) => $q->withoutGlobalScope(ActiveScope::class)])->get();
```

### Scope Name Collision

Two global scopes of the same class on the same model — the second registration silently overwrites the first. Use separate classes or string-named closures when applying variant constraints.

### Using `withoutGlobalScope` on Non-Existent Scope

Calling `withoutGlobalScope(SomeScope::class)` when that scope is not registered does nothing and raises no error. Verify scope registration before debugging unexpected query results.
