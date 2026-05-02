# Model Observers and Events Reference

## Model Event Lifecycle Order

The following events fire in this exact order during each operation. Understanding the sequence is critical for placing logic in the correct hook.

**Creating / Updating (write operations):**

```
creating  → (before INSERT — model does not yet have an id)
created   → (after INSERT — id is now set)

updating  → (before UPDATE)
updated   → (after UPDATE)

saving    → (fires before both creating and updating)
saved     → (fires after both created and updated)
```

**Deleting:**

```
deleting  → (before DELETE or soft delete)
deleted   → (after DELETE or soft delete)
```

**Soft Delete Restore:**

```
restoring → (before restoring a soft-deleted record)
restored  → (after restoring)
```

**Replication (`$model->replicate()`):**

```
replicating → (before the new instance is created)
```

Note: `saving` fires before `creating` and before `updating`. `saved` fires after `created` and after `updated`.

## Complete Observer Class

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Log;

final class UserObserver
{
    public function creating(User $user): void
    {
        // Fires before INSERT; $user has no id yet
        $user->uuid = (string) \Illuminate\Support\Str::uuid();
    }

    public function created(User $user): void
    {
        // Fires after INSERT; $user->id is available
        \App\Jobs\SendWelcomeEmail::dispatch($user);
    }

    public function updating(User $user): void
    {
        // Fires before UPDATE
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
    }

    public function updated(User $user): void
    {
        Log::info('User updated', ['id' => $user->id, 'changes' => $user->getChanges()]);
    }

    // Observer "ing" methods (creating, updating, saving, deleting, restoring,
    // replicating) CAN cancel the operation by returning false. Laravel's
    // Model::fireModelEvent() uses $halt = true for these events.
    //
    // After-events (created, updated, deleted, etc.) ignore the return value.
    //
    // Use `return false;` to cancel idiomatically. Throw exceptions only when
    // you need an actual error (which should rollback transactions, etc.).
    public function saving(User $user): bool|null
    {
        if ($user->name === 'BANNED') {
            return false; // cleanly cancels the save
        }

        return null;
    }

    public function saved(User $user): void
    {
        cache()->forget("user:{$user->id}");
    }

    public function deleting(User $user): void
    {
        // Fires before soft or hard delete
        $user->tokens()->delete();
    }

    public function deleted(User $user): void
    {
        Log::info('User deleted', ['id' => $user->id]);
    }

    public function restoring(User $user): void
    {
        // Fires before a soft-deleted record is restored
    }

    public function restored(User $user): void
    {
        Log::info('User restored', ['id' => $user->id]);
    }

    public function forceDeleting(User $user): void
    {
        // Fires before permanent deletion (only with SoftDeletes)
        $user->profile()->forceDelete();
    }

    public function forceDeleted(User $user): void
    {
        Log::info('User permanently deleted', ['id' => $user->id]);
    }
}
```

## Registering Observers

### Via Attribute (Laravel 11+, Recommended)

Apply the `#[ObservedBy]` attribute directly on the model class. No service provider registration required.

```php
<?php

namespace App\Models;

use App\Observers\UserObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(UserObserver::class)]
class User extends Model
{
    // ...
}
```

### Via AppServiceProvider (Laravel 10 and below, or if multiple observers are managed centrally)

```php
<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        User::observe(UserObserver::class);
    }
}
```

Register multiple observers on one model if needed:

```php
User::observe([UserObserver::class, AuditObserver::class]);
```

## Decision Guide: Observers vs Inline Events vs Domain Events

| Approach | Use when |
|---|---|
| **Observer class** | Multiple lifecycle hooks needed for one model; logic is cross-cutting (logging, cache invalidation, audit). |
| **Inline model event** (`static::creating(fn() => ...)` in `booted()`) | Simple, single-line side effect tightly bound to the model. |
| **Domain event + Listener** | The side effect belongs to application logic, not infrastructure; needs to be tested independently; may be deferred or queued. |

Observers are infrastructure. Domain events are business logic. Keep them separate.

## `withoutEvents` — Suppress Events During Seeding and Testing

Wrap operations in `withoutEvents` to prevent observers and listeners from firing. Use in seeders, data migrations, and tests where side effects are undesirable.

```php
use Illuminate\Database\Eloquent\Model;

// Suppress events for a single model
User::withoutEvents(function (): void {
    User::factory()->count(100)->create();
});

// Suppress events globally for a block
Model::withoutEvents(function (): void {
    User::create(['name' => 'Seed User', 'email' => 'seed@example.com', 'password' => 'secret']);
    Post::factory()->count(50)->create();
});
```

In tests, suppress observer events for a single block with `Model::withoutEvents()`:

```php
Model::withoutEvents(function () {
    User::factory()->count(100)->create();
});
```

## `dispatchesEvents` — Mapping Model Events to Custom Event Classes

Map model lifecycle events to custom event classes using the `$dispatchesEvents` property. Laravel fires these events automatically.

```php
<?php

namespace App\Models;

use App\Events\UserCreatedEvent;
use App\Events\UserDeletedEvent;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $dispatchesEvents = [
        'created' => UserCreatedEvent::class,
        'deleted' => UserDeletedEvent::class,
    ];
}
```

The model instance is passed to the event constructor automatically. Define a public `$user` property (or constructor parameter) in the event class.

```php
final class UserCreatedEvent
{
    public function __construct(public readonly User $user) {}
}
```

Register listeners by placing them in `app/Listeners/` (auto-discovered when they typehint the event in `handle()`), or manually via `Event::listen(UserCreatedEvent::class, SendWelcomeEmail::class)` in `AppServiceProvider::boot()`.

## Critical Pitfall: `query()->update()` Does NOT Fire Observers

**Mass updates via query builder bypass model events entirely.** Observers, `$dispatchesEvents`, and inline events all rely on model instances being hydrated and saved individually.

```php
// WRONG — observers do NOT fire; no 'updated' event
User::where('is_active', false)->update(['is_active' => true]);

// CORRECT — each model is hydrated, observer fires for each
User::where('is_active', false)->get()->each->update(['is_active' => true]);

// Also fires observers (explicit save)
User::where('is_active', false)->each(function (User $user): void {
    $user->is_active = true;
    $user->save();
});
```

The same applies to `delete()` on a query: `User::where(...)->delete()` does not fire `deleting`/`deleted` on each model. Use `->each->delete()` when observers must fire.

This is one of the most common sources of missed side effects in Laravel applications.
