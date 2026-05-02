# Indexes and Foreign Keys

## Index Types

### Single Column Index

```php
$table->index('status');
$table->index('email');
$table->index('created_at');
```

Use when a column appears frequently in `WHERE`, `ORDER BY`, or `GROUP BY` clauses on its own.

### Composite Index

```php
$table->index(['user_id', 'created_at']);
$table->index(['user_id', 'status', 'created_at'], 'idx_user_status_created');
```

Column order matters. The index supports queries that filter on the leftmost column(s). An index on `[user_id, status]` supports `WHERE user_id = ?`, `WHERE user_id = ? AND status = ?`, but not `WHERE status = ?` alone.

Name composite indexes explicitly in large schemas to avoid cryptic auto-generated names:

```php
$table->index(['tenant_id', 'user_id', 'created_at'], 'idx_tenant_user_created');
```

### Unique Index

```php
$table->unique('email');
$table->unique('slug');
$table->unique(['user_id', 'post_id']); // composite unique
```

Unique indexes enforce the constraint at the database level. Never rely on PHP validation alone — concurrent requests can both pass validation before either commits.

### Full-Text Index

```php
$table->fullText('body');                          // single column
$table->fullText(['title', 'body'], 'ft_content'); // multi-column
```

Full-text indexes enable `MATCH ... AGAINST` queries on MySQL and `to_tsvector` / GIN indexes on PostgreSQL. Supported on MySQL (InnoDB, MySQL 5.6+) and PostgreSQL. Not supported on SQLite.

Use for user-facing text search on large text columns. For simple pattern matching (`LIKE '%term%'`), a full-text index provides no benefit.

---

## Dropping Indexes

```php
// Drop by column array (Laravel derives the index name)
$table->dropIndex(['email']);
$table->dropUnique(['email']);
$table->dropIndex(['user_id', 'created_at']);

// Drop by explicit index name
$table->dropIndex('idx_tenant_user_created');
$table->dropFullText(['body']);
```

Always drop the index in `down()` when `up()` adds one.

---

## Index Naming Conventions

Laravel generates index names following the pattern:

```
{table}_{column(s)}_{type}
```

Examples:
- `orders_status_index`
- `orders_user_id_created_at_index`
- `users_email_unique`

On large schemas with long table or column names, auto-generated names can exceed the 64-character identifier limit on MySQL. Provide explicit names when necessary:

```php
$table->index(['user_id', 'created_at'], 'idx_orders_user_created');
```

---

## When to Add an Index

**Always index:**
- Always index every foreign key column. MySQL/InnoDB auto-creates an index for FK columns; PostgreSQL does NOT. Verify in MySQL with `SHOW INDEX FROM <table>` and in PostgreSQL with `\d <table>` or by querying `pg_indexes`. Note: `migrate:status` only reports which migrations have run — it cannot show indexes.
- Columns in frequent `WHERE` clauses on large tables
- Columns in `ORDER BY` when the result set is not trivially small
- Columns used in `whereHas()` constraints (they generate sub-queries with `WHERE`)
- Columns used in `GROUP BY` aggregations

**Do not index:**
- Every column by default — each index slows `INSERT`, `UPDATE`, and `DELETE`
- Columns with very low cardinality (e.g., a boolean `is_active` with 90% of rows as `true`) — the query planner often ignores such indexes and does a full table scan anyway
- Columns that are never queried directly

A good rule: add indexes driven by slow query analysis (`EXPLAIN`, Laravel Telescope query tab, or `mysql> EXPLAIN SELECT ...`), not pre-emptively.

---

## Foreign Key Shorthand

### Targeting a Conventionally Named Table

```php
// Creates user_id column (bigint unsigned) + foreign key to users.id
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
```

`constrained()` without arguments infers the table name from the column name: `user_id` → `users`.

### Targeting an Explicitly Named Table

```php
// author_id → users.id (column name does not match table name)
$table->foreignId('author_id')->constrained('users')->cascadeOnDelete();

// reviewer_id → employees.id
$table->foreignId('reviewer_id')->constrained('employees')->restrictOnDelete();
```

### Nullable Foreign Key

```php
$table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
```

A nullable foreign key requires `->nullable()` before `->constrained()`. The column will accept `NULL` when no related record exists.

---

## Cascade Behaviour — Always Explicit

Never leave the cascade behaviour implicit. The default varies by database engine and is not obvious to future readers.

```php
->cascadeOnDelete()   // DELETE parent → DELETE children (use for ownership)
->restrictOnDelete()  // DELETE parent → ERROR if children exist (use for soft relationships)
->nullOnDelete()      // DELETE parent → SET child FK to NULL (nullable FK required)
->noActionOnDelete()  // Similar to restrict; deferred check on some engines

->cascadeOnUpdate()   // UPDATE parent PK → UPDATE child FK (rarely needed; avoid unless required)
```

**Use `cascadeOnDelete()`** when the child record has no meaning without the parent (e.g., `order_items` belongs to `orders`).

**Use `restrictOnDelete()`** when deleting the parent should be blocked if children exist (e.g., deleting a `user` when they have `orders` in progress).

**Use `nullOnDelete()`** when the child can exist independently but optionally references the parent (e.g., a `post` optionally assigned to a `category`).

---

## Full Foreign Key Syntax (Explicit Form)

When more control is needed than the shorthand provides:

```php
$table->unsignedBigInteger('user_id');
$table->foreign('user_id')
      ->references('id')
      ->on('users')
      ->cascadeOnDelete()
      ->cascadeOnUpdate();
```

Use the explicit form when the foreign key references a non-`id` column on the parent table.

---

## Disabling Foreign Key Constraints During Seeding

Seeding with relational data can fail if seeders run in an order that violates FK constraints:

```php
// DatabaseSeeder.php
public function run(): void
{
    Schema::disableForeignKeyConstraints();

    $this->call([
        UserSeeder::class,
        OrderSeeder::class,
        OrderItemSeeder::class,
    ]);

    Schema::enableForeignKeyConstraints();
}
```

Always re-enable constraints after seeding. Leaving them disabled masks data integrity bugs.

---

## PostgreSQL vs MySQL Differences

| Feature | MySQL (InnoDB) | PostgreSQL |
|---|---|---|
| `fullText()` support | Yes (5.6+ InnoDB) | Yes (GIN index generated) |
| `->after('col')` column ordering | Supported | Ignored |
| `ADD COLUMN NULL` speed | Near-instant (metadata) | Near-instant |
| `ADD COLUMN DEFAULT value` | Full table rewrite (< MySQL 8.0.12). INSTANT ADD COLUMN is available from MySQL 8.0.12+ for many cases, but not all (compressed rows, FULLTEXT tables, and row-version limits force a table copy). Verify with explicit `ALGORITHM=INSTANT` in tests. The conservative pattern (nullable first, backfill, set default) remains the safe default. | Near-instant (stores default in catalog) |
| Index name limit | 64 characters | 63 characters |
| Partial indexes | Not supported (except via expressions in 8.x) | Supported via `whereRaw()` |
| Concurrent index build | Not supported (locks table) | `CREATE INDEX CONCURRENTLY` (no lock) |

For PostgreSQL, `ADD COLUMN DEFAULT value` is safe and does not rewrite the table (the default is stored in `pg_attrdef` and applied at read time). The large-table caution about defaults applies specifically to MySQL versions before 8.0.12.

When using PostgreSQL and zero-downtime is required for large-table index additions, use a raw migration to build the index concurrently. Both `up()` and `down()` must run **outside** a transaction — declare `$withinTransaction = false` once on the migration class so a copy-paste user doesn't end up with `down()` failing inside a transaction:

```php
return new class extends Migration
{
    // CONCURRENTLY cannot run inside a transaction — applies to both up() and down().
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_events_category ON events(category)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_events_category');
    }
};
```
