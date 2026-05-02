---
name: laravel-migrations
description: This skill should be used when the user asks to "create a migration", "add a column", "modify a table", "add an index", "rename a column", "drop a table", "write a database migration", or when making any schema changes in a Laravel 12 project.
version: 1.0.0
---

## Migration File Conventions

Use descriptive, action-prefixed names for every migration file:

- `create_orders_table` — new table
- `add_status_to_orders_table` — add a column
- `drop_legacy_tokens_table` — drop a table
- `rename_user_name_to_full_name_in_users_table` — rename a column

Use `Schema::create()` for new tables, `Schema::table()` for modifications to existing tables.

Every `up()` must have a matching `down()` that perfectly and completely reverses it. If `up()` adds three columns, `down()` drops all three — in the correct order to satisfy foreign key constraints.

Never modify an existing migration that has already run in production. Create a new migration instead.

Laravel 9+ generates anonymous migration classes by default — keep them anonymous to avoid class name collisions across packages:

```php
return new class extends Migration
{
    public function up(): void { /* ... */ }
    public function down(): void { /* ... */ }
};
```

## Column Conventions

Always define `$table->id()` as the primary key (bigint unsigned auto-increment).

Always add `$table->timestamps()` to every table.

Add `$table->softDeletes()` when the model uses the `SoftDeletes` trait.

**Choose the right column type:**

| Data | Column method |
|---|---|
| Short strings, slugs, codes | `string('col', 100)` (varchar) |
| Long user content | `text('col')` |
| Foreign key target | `unsignedBigInteger('user_id')` or `foreignId('user_id')` |
| Money / prices | `decimal('amount', 10, 2)` |
| Flexible / schema-less data | `json('settings')` |
| Status / category | Backed enum cast on `string` column |
| Boolean flags | `boolean('is_active')->default(false)` |
| Timestamps (point in time) | `timestamp('sent_at')->nullable()` |

**NEVER use `float` or `double` for monetary values.** Floating-point arithmetic introduces rounding errors. Always use `decimal($precision, $scale)`.

Mark columns nullable with `->nullable()` only when the data is genuinely optional. Do not make a column nullable simply to avoid providing a default value.

## Safe Schema Changes on Production Tables

Applying naive schema changes to live tables causes downtime, data loss, or hard-to-detect bugs.

### Adding a NOT NULL Column

Perform across exactly two deploys:

1. **Deploy 1:** Add the column as `->nullable()` with no default. The migration runs without touching existing rows.
2. **Deploy 2:** In a single migration, backfill existing rows (via `DB::table()->whereNull('column')->update(...)`) and then call `->nullable(false)->change()` to enforce the NOT NULL constraint.

> For tables with more than ~100k rows, move the backfill out of the migration into a queued job before running the `nullable(false)` migration — otherwise the deploy blocks until backfill completes.

### Renaming a Column

Perform across three deploys:

1. **Deploy 1:** Add the new column alongside the old one. Write to both columns in application code.
2. **Deploy 2:** Read from the new column. Copy any remaining old data. Stop writing to the old column.
3. **Deploy 3:** Drop the old column.

Never rename a column with data in one step — the rename will break any running instance of the application that still references the old name.

### Removing a Column

Perform across two deploys:

1. **Deploy 1:** Remove all references to the column from queries, models, resources, and factories. Deploy and verify.
2. **Deploy 2:** Drop the column in a migration.

Never drop a column that is still referenced in application code. The old code will error on queries the moment the column disappears.

### Large Table ALTER TABLE Risks

On MySQL, `ADD COLUMN ... DEFAULT value` on a table with millions of rows rewrites the entire table and holds a write lock for the duration. The safe alternative:

1. Add the column as `->nullable()` — this is metadata-only and near-instant on InnoDB.
2. Backfill via a queued job that processes rows in chunks.
3. Add `->nullable(false)->default(value)` in a follow-up migration after the backfill completes.

For very large tables (hundreds of millions of rows), consider `gh-ost` or `pt-online-schema-change` instead of direct `ALTER TABLE`.

## Indexes

Add an index on every foreign key column.

Add indexes on columns used in frequent `WHERE`, `ORDER BY`, and `GROUP BY` clauses.

```php
$table->index('status');
$table->index(['user_id', 'created_at']); // composite
$table->unique('email');
$table->fullText('body'); // MySQL / PostgreSQL only
```

Use `$table->unique()` to enforce uniqueness constraints at the database level. Never rely on PHP-only validation for uniqueness — concurrent requests will bypass it.

Do not index every column. Every index slows down `INSERT`, `UPDATE`, and `DELETE` on the table. Add indexes only where query patterns justify them.

## Foreign Keys

Always define explicit foreign key constraints. Never leave referential integrity to application code alone.

Use the shorthand form when the foreign key targets `id` on the conventionally named table:

```php
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
```

Specify the target table explicitly when the column name does not match the convention:

```php
$table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
```

Always declare the cascade behaviour explicitly — `cascadeOnDelete()`, `restrictOnDelete()`, or `nullOnDelete()`. Never leave it implicit.

## Artisan Commands Reference

```bash
# Generate migrations
php artisan make:migration create_orders_table --create=orders
php artisan make:migration add_status_to_orders_table --table=orders

# Run migrations
php artisan migrate
php artisan migrate --pretend        # preview SQL without running
php artisan migrate:status           # show which migrations have run

# Roll back
php artisan migrate:rollback         # last batch
php artisan migrate:rollback --step=3
php artisan migrate:reset            # roll back all

# Dev only — never run on production
php artisan migrate:fresh            # drop all tables, re-run everything
php artisan migrate:fresh --seed
```

## Additional Resources

- `references/writing-migrations.md` — Full create and modify migration examples, column options, conditional schema checks, computed columns, anonymous migration classes, and all artisan migration commands.
- `references/safe-schema-changes.md` — Zero-downtime patterns: the 3-deploy column rename, adding NOT NULL columns safely, removing columns safely, large table ALTER risks, and when `migrate:fresh` is acceptable.
- `references/indexes-foreign-keys.md` — Index types and naming, composite indexes, unique and full-text indexes, foreign key shorthand, cascade options, disabling constraints during seeding, and PostgreSQL vs MySQL differences.
