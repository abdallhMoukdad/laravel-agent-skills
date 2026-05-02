# Writing Migrations

## Generating Migration Files

```bash
# New table
php artisan make:migration create_orders_table --create=orders

# Modify existing table
php artisan make:migration add_status_to_orders_table --table=orders

# Freeform (name only, no scaffold)
php artisan make:migration drop_legacy_tokens_table
```

Migration files land in `database/migrations/` with a UTC timestamp prefix that controls execution order. The `--create` and `--table` flags pre-fill the `Schema::create()` / `Schema::table()` scaffolding.

## Anonymous Migration Classes

Laravel 9+ generates anonymous classes by default. Always keep them anonymous to prevent class name collisions across packages:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ...
    }

    public function down(): void
    {
        // ...
    }
};
```

## Full Create Migration Example

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();                                       // bigint unsigned auto-increment PK
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 32)->unique();         // varchar 32, unique index
            $table->string('status', 50)->default('pending');  // short enum-like string
            $table->text('notes')->nullable();                 // large text, optional

            $table->decimal('subtotal', 10, 2);                // money — never float
            $table->decimal('tax', 10, 2)->default('0.00');
            $table->decimal('total', 10, 2);

            $table->string('currency', 3)->default('USD');
            $table->json('metadata')->nullable();              // flexible schema-less data
            $table->boolean('is_paid')->default(false);

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();

            $table->timestamps();                              // created_at, updated_at
            $table->softDeletes();                             // deleted_at (when model uses SoftDeletes)

            $table->index(['user_id', 'status']);              // composite index for common queries
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
```

## Full Modify Migration Example

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Add column
            $table->string('tracking_number', 100)->nullable()->after('shipped_at');

            // Add index
            $table->index('tracking_number');

            // Drop column
            $table->dropColumn('legacy_code');

            // Rename column (requires doctrine/dbal on Laravel < 11, native on Laravel 11+)
            $table->renameColumn('note', 'internal_note');

            // Modify column type or constraint
            $table->string('reference', 64)->change(); // widen varchar from 32 to 64
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tracking_number']);
            $table->dropColumn('tracking_number');

            $table->string('legacy_code', 50)->nullable();

            $table->renameColumn('internal_note', 'note');

            $table->string('reference', 32)->change();
        });
    }
};
```

## Column Ordering (MySQL Only)

Use `->after('column_name')` to place a column after an existing one. This is a MySQL-specific feature and has no effect on PostgreSQL or SQLite:

```php
$table->string('middle_name')->nullable()->after('first_name');
```

Do not rely on column ordering for application logic — it is cosmetic only.

## Column Documentation

Use `->comment('...')` to attach a description to a column. The comment is stored in the database schema and visible in tools like TablePlus or `SHOW FULL COLUMNS`:

```php
$table->decimal('total', 10, 2)->comment('Order total in the currency field');
```

## Default Values

Use `->default()` for values that are genuinely static and do not require application logic:

```php
$table->boolean('is_active')->default(true);
$table->string('currency', 3)->default('USD');
$table->integer('retry_count')->default(0);
```

**Do NOT use `->default()` on large production tables during an `ADD COLUMN` migration.** MySQL (< 8.0.12) will rewrite the entire table to apply the default to existing rows, holding a write lock. On MySQL 8.0.12+, `ADD COLUMN ... DEFAULT value` is instant and does not rewrite the table. Add the column as `->nullable()` first, backfill via a job, then add the default in a follow-up migration.

## Computed Columns

Use `->storedAs()` for a computed column whose value is persisted to disk and updated on write:

```php
$table->decimal('total', 10, 2)->storedAs('subtotal + tax');
```

Use `->virtualAs()` for a computed column that is recalculated on every read (not stored):

```php
$table->string('full_name')->virtualAs("CONCAT(first_name, ' ', last_name)");
```

Stored columns can be indexed. Virtual columns cannot (on most engines).

## Conditional Schema Checks

Use these when writing migrations that must be safe to run in multiple environments where the schema may differ:

```php
if (! Schema::hasTable('orders')) {
    Schema::create('orders', function (Blueprint $table) {
        // ...
    });
}

if (! Schema::hasColumn('orders', 'tracking_number')) {
    Schema::table('orders', function (Blueprint $table) {
        $table->string('tracking_number', 100)->nullable();
    });
}
```

Prefer idempotent migrations in CI pipelines and when re-running seeds across environments.

## Running Migrations

```bash
# Run all pending migrations
php artisan migrate

# Preview the SQL that would be run without executing it
php artisan migrate --pretend

# Show migration status (ran / pending / batch number)
php artisan migrate:status

# Force run in production (skips the confirmation prompt)
php artisan migrate --force
```

## Rolling Back Migrations

```bash
# Roll back the last batch of migrations
php artisan migrate:rollback

# Roll back a specific number of migrations
php artisan migrate:rollback --step=3

# Roll back all migrations (runs every down())
php artisan migrate:reset

# Drop all tables and re-run everything — DEV AND CI ONLY, never production
php artisan migrate:fresh
php artisan migrate:fresh --seed
```

`migrate:fresh` does not call `down()` — it drops all tables directly. It is safe and fast in local development and CI. It is destructive and must never be run against a production or staging database with real user data.
