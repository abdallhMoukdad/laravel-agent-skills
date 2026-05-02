# Safe Schema Changes

Zero-downtime deployments require treating schema changes and code changes as separate, coordinated steps. The database and the application code run simultaneously during a deployment window — a migration that removes or renames a column will break the in-flight requests of the old code that is still running.

## Core Principle

**Schema changes must be backwards-compatible with the code that is currently deployed.** Additive changes (new columns, new tables, new indexes) are almost always safe. Destructive changes (dropping columns, renaming columns, changing types) require a multi-step approach.

---

## Adding a NOT NULL Column

A single migration adding a `NOT NULL` column without a default fails on non-empty tables. Even with a default, MySQL will lock the table on large datasets to rewrite existing rows.

### The 2-Deploy Pattern

**Deploy 1 — Add as nullable:**

```php
Schema::table('orders', function (Blueprint $table) {
    $table->string('source_channel', 50)->nullable();
});
```

This migration is instant — no existing rows are touched.

**Deploy 2 — Backfill, then make NOT NULL:**

Backfill via a migration using chunked updates:

```php
public function up(): void
{
    DB::table('orders')
        ->whereNull('source_channel')
        ->orderBy('id')
        ->chunkById(1000, function ($rows) {
            foreach ($rows as $row) {
                DB::table('orders')
                    ->where('id', $row->id)
                    ->update(['source_channel' => 'web']);
            }
        });

    Schema::table('orders', function (Blueprint $table) {
        $table->string('source_channel', 50)->nullable(false)->default('web')->change();
    });
}
```

For very large tables, move the backfill to a queued job and run the `->nullable(false)` migration after the job completes.

---

## Renaming a Column — The 3-Deploy Pattern

Never rename a column in one step on a live database. The old column name disappears the moment the migration runs, breaking every query from running application instances that still reference it.

### Deploy 1 — Add the new column, write to both

```php
// Migration: add new column
Schema::table('users', function (Blueprint $table) {
    $table->string('full_name')->nullable()->after('email');
});
```

Update application code to write to both `name` (old) and `full_name` (new). Read from `name` still.

### Deploy 2 — Read from new column, stop writing to old

Backfill `full_name` from `name` for rows where it is still null:

```php
DB::table('users')
    ->whereNull('full_name')
    ->chunkById(1000, function ($rows) {
        foreach ($rows as $row) {
            DB::table('users')
                ->where('id', $row->id)
                ->update(['full_name' => $row->name]);
        }
    });
```

Update application code to read from `full_name`. Stop writing to `name`.

### Deploy 3 — Drop the old column

```php
Schema::table('users', function (Blueprint $table) {
    $table->dropColumn('name');
});
```

---

## Removing a Column — The 2-Deploy Pattern

### Deploy 1 — Remove all code references

Remove the column from:

- Eloquent `$fillable` and `$casts` arrays
- Query builder references (`where`, `select`, `orderBy`)
- API resources and transformers
- Form requests and validation rules
- Factory definitions
- Test fixtures and seeders

Deploy and verify in production that no error logs reference the column.

### Deploy 2 — Drop the column

```php
Schema::table('orders', function (Blueprint $table) {
    $table->dropColumn('legacy_promo_code');
});
```

---

## Renaming a Table

Renaming a table is riskier than renaming a column because it affects every query that references the table, including joins in other models.

The safest approach for active tables:

1. Create the new table with the new name (or `Schema::rename()` during a maintenance window).
2. Update all model `$table` properties and query builder references.
3. If zero-downtime is required, create a database view with the old name pointing to the new table, update all code, then drop the view in a follow-up deploy.

```php
// Option: rename (requires a maintenance window)
Schema::rename('user_tokens', 'personal_access_tokens');
```

---

## Large Table ALTER TABLE Risks (MySQL)

On MySQL with InnoDB, the following operations rewrite the entire table and hold a **write lock** for the duration:

- `ADD COLUMN ... DEFAULT non-null-value` (MySQL < 8.0.12 always rewrites). INSTANT ADD COLUMN is available from MySQL 8.0.12+ for many cases, but not all (compressed rows, FULLTEXT tables, and row-version limits force a table copy). Verify with explicit `ALGORITHM=INSTANT` in tests. The conservative pattern (nullable first, backfill, set default) remains the safe default.
- `MODIFY COLUMN` changing the data type
- `ADD INDEX` on a table not yet indexed (online DDL may help depending on version)

**The safe pattern for large tables:**

```php
// Step 1: Add as nullable — metadata only, near-instant on InnoDB
Schema::table('events', function (Blueprint $table) {
    $table->string('category', 100)->nullable();
});

// Step 2: Backfill via a queued job (chunked, does not lock)
// Step 3: After job completes, add the NOT NULL constraint
Schema::table('events', function (Blueprint $table) {
    $table->string('category', 100)->nullable(false)->default('general')->change();
});
```

### External Tools for Very Large Tables

For tables with hundreds of millions of rows, direct `ALTER TABLE` is unacceptable even with nullable columns. Use:

- **`gh-ost`** (GitHub Online Schema Migrations) — creates a shadow table, migrates data via binlog replication, then does a near-instant cutover.
- **`pt-online-schema-change`** (Percona Toolkit) — uses triggers to shadow-copy the table, then swaps.

Both tools work outside Laravel migrations. Run them manually and record the migration as a no-op or skip it.

---

## The `$table->after()` Trap

`->after('column_name')` only affects MySQL. On PostgreSQL and SQLite it is silently ignored. Never rely on column ordering for application logic — only use it for cosmetic schema organisation when MySQL is the target database.

---

## When `migrate:fresh` Is Acceptable

`php artisan migrate:fresh` drops every table without calling `down()`, then re-runs all migrations from scratch.

**Acceptable:**
- Local development machines
- CI pipelines on ephemeral environments
- Staging environments with no real user data (reset before a test run)

**Never acceptable:**
- Production
- Staging with real user data migrated from production
- Any environment where data recovery is not trivially possible

---

## Testing Migrations

Every migration should be tested in CI. Verify that:

1. `up()` runs without error on a clean database.
2. `down()` fully reverses `up()` without error.
3. Running `up()` twice is idempotent (use `Schema::hasColumn()` guards where needed).

A basic migration test using `RefreshDatabase`:

```php
it('adds the tracking_number column to orders', function () {
    expect(Schema::hasColumn('orders', 'tracking_number'))->toBeTrue();
});

it('rolls back the tracking_number column', function () {
    $migration = require database_path('migrations/2024_01_15_000001_add_tracking_number_to_orders_table.php');
    $migration->down();

    expect(Schema::hasColumn('orders', 'tracking_number'))->toBeFalse();
});
```

Run rollback tests in CI to catch broken `down()` methods before they reach production.
