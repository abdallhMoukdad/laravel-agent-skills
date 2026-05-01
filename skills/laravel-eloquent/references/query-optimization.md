# Eloquent Query Optimization Reference

## Limit Columns with `select()`

Always specify only the columns needed for large result sets. Fetching every column wastes memory and serialization time, especially on wide tables.

```php
// Fetch only what is needed
$users = User::select(['id', 'name', 'email'])->get();

// In a relationship eager load
$posts = Post::with(['author' => fn($q) => $q->select(['id', 'name'])])->get();
```

Never call `select()` without including the foreign key when using it inside an eager load constraint — omitting it breaks the relationship mapping.

## `chunk()` — Batch Processing with Low Memory

Process large datasets in fixed-size batches. Each batch fires a new query; memory stays bounded.

```php
User::where('is_active', true)->chunk(1000, function ($users): void {
    foreach ($users as $user) {
        ProcessUser::dispatch($user);
    }
});
```

Do not modify records that affect the order/offset column inside a `chunk()` loop — use `chunkById()` instead to avoid skipped records.

```php
User::where('is_active', false)->chunkById(500, function ($users): void {
    foreach ($users as $user) {
        $user->delete(); // safe with chunkById
    }
});
```

## `cursor()` — Server-Side Cursor with LazyCollection

`cursor()` retrieves one row at a time from the database using a PHP generator. Memory usage stays constant regardless of result set size. Returns a `LazyCollection`.

```php
foreach (User::where('is_active', true)->cursor() as $user) {
    ProcessUser::dispatch($user);
}
```

Use `cursor()` when each record must be processed individually and the operation is sequential. Avoid `cursor()` for operations that need random access or re-iteration.

## `lazy()` — Chunked LazyCollection

`lazy()` uses `chunk()` internally but returns a `LazyCollection` for a fluent API. Specify chunk size as the argument (default 1000).

```php
User::where('is_active', true)->lazy(500)->each(function (User $user): void {
    ProcessUser::dispatch($user);
});
```

`lazy()` is safer than `cursor()` for queries with `ORDER BY` or when pagination logic matters — it re-queries using offset/limit rather than a server cursor.

## `toBase()` — Skip Model Hydration

`toBase()` returns a collection of `stdClass` objects instead of Eloquent model instances. No model events, no casts, no accessors. Use for read-only reporting queries where model overhead is unnecessary.

```php
$rows = User::select(['id', 'name', 'email'])
    ->where('is_active', true)
    ->toBase()
    ->get();

// $rows is Collection of stdClass, not Eloquent models
```

Do not use `toBase()` when the result needs to go through casts, relationships, or model methods.

## Avoid `->count()` on a Loaded Collection

Calling `->count()` on an already-loaded Eloquent collection is correct and performs no query. However, calling `->count()` when the collection has not been loaded triggers a query. Use the query builder count directly when the collection is not needed.

```php
// Wrong — loads entire collection into memory just to count
$count = User::all()->count();

// Correct — single COUNT(*) query, no hydration
$count = User::count();
$count = User::where('is_active', true)->count();
```

## `whereKey()` — Primary Key Lookups

`whereKey()` uses the model's primary key column and is always indexed. Prefer it over `where('id', $id)` for explicit intent and compatibility with custom primary keys.

```php
User::whereKey(42)->first();
User::whereKey([1, 2, 3])->get();

// Equivalent to: find() — also uses primary key index
User::find(42);
User::findOrFail([1, 2, 3]);
```

## Query Debugging

### `toSql()` — Inspect the Generated SQL

```php
$sql = User::where('is_active', true)->toSql();
// Returns: "select * from `users` where `is_active` = ?"
```

Bindings are not included. Use `getBindings()` alongside `toSql()` for the full picture.

```php
$query = User::where('is_active', true);
dump($query->toSql(), $query->getBindings());
```

### `dd()` and `dump()` — Die-and-Dump Shortcuts

```php
User::where('is_active', true)->dd();   // outputs SQL + bindings and dies
User::where('is_active', true)->dump(); // outputs without dying
```

### `DB::enableQueryLog()` / `DB::getQueryLog()`

Capture all queries fired during a block of code.

```php
use Illuminate\Support\Facades\DB;

DB::enableQueryLog();

$users = User::with('posts')->where('is_active', true)->get();

$log = DB::getQueryLog();
dump($log);
// Each entry: ['query' => '...', 'bindings' => [...], 'time' => 1.23]

DB::disableQueryLog();
```

## `DB::listen()` — Log All Queries in Development

Add to `App\Providers\AppServiceProvider::boot()` to log every query in non-production environments. Remove or guard this before deploying.

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

public function boot(): void
{
    if (! app()->isProduction()) {
        DB::listen(function ($query): void {
            Log::debug('SQL', [
                'sql'      => $query->sql,
                'bindings' => $query->bindings,
                'time_ms'  => $query->time,
            ]);
        });
    }
}
```

## Optimization Summary Table

| Technique | Best for |
|---|---|
| `select(['col1', 'col2'])` | Wide tables, API responses, exports |
| `chunk(1000, fn)` | Bulk updates, jobs, writes on large tables |
| `chunkById(1000, fn)` | Bulk deletes or updates that modify the sort key |
| `cursor()` | Sequential read-only processing, constant memory |
| `lazy(1000)` | Read-only processing with LazyCollection API |
| `toBase()->get()` | Reporting, aggregates, no model needed |
| `withCount() / withSum()` | Dashboard counts, avoid loading collections |
| `DB::enableQueryLog()` | Debug query count during development |
| `DB::listen()` | Continuous query logging in development |
| `->dd()` / `->toSql()` | Inspect generated SQL inline |
