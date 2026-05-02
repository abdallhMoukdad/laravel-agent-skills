## Actions Reference

### Plain Action Pattern (No Package)

An action is a `final` class with a single `handle()` method. No base class, no trait, no magic.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Data\CreateUserData;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

final class CreateUser
{
    public function __construct(
        private readonly Hasher $hasher,
    ) {}

    public function handle(CreateUserData $data): User
    {
        return User::create([
            'name'     => $data->name,
            'email'    => $data->email,
            'password' => $this->hasher->make($data->password),
        ]);
    }
}
```

Resolve and call from a controller:

```php
public function store(StoreUserRequest $request, CreateUser $action): JsonResponse
{
    $user = $action->handle(CreateUserData::from($request->validated()));

    return UserResource::make($user)->response()->setStatusCode(201);
}
```

### `lorisleiva/laravel-actions` Package

The `AsAction` trait turns a single class into an action, a queued job, and an Artisan command simultaneously.

```bash
composer require lorisleiva/laravel-actions
```

```php
<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Subscription;
use App\Models\Payment;
use Lorisleiva\Actions\Concerns\AsAction;

final class ChargeSubscription
{
    use AsAction;

    public string $jobQueue = 'billing';

    public int $tries = 3;

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Subscription $subscription): Payment
    {
        // Core logic — runs the same whether called sync or async
        $charge = $this->gateway->charge(
            amount: $subscription->plan->price_cents,
            method: $subscription->paymentMethod,
        );

        return Payment::create([
            'subscription_id' => $subscription->id,
            'amount_cents'    => $charge->amount,
            'gateway_id'      => $charge->id,
            'paid_at'         => now(),
        ]);
    }
}
```

**Synchronous execution:**

```php
$payment = ChargeSubscription::run($subscription);
```

**Async dispatch (queued job):**

```php
ChargeSubscription::dispatch($subscription);
```

**Check execution context inside `handle()`:**

```php
public function handle(Subscription $subscription): Payment
{
    if ($this->runningAs('job')) {
        // Reload model to avoid stale data from the queue payload
        $subscription = $subscription->fresh();
    }
    // ...
}
```

**As an Artisan command**, add `asCommand()`:

```php
public function asCommand(Command $command): void
{
    $id  = $command->argument('subscription');
    $sub = Subscription::findOrFail($id);

    $payment = $this->handle($sub);

    $command->info("Charged subscription #{$sub->id}. Payment: {$payment->id}");
}

public function getCommandSignature(): string
{
    return 'billing:charge-subscription {subscription : The subscription ID}';
}
```

### Naming Conventions

Always `final`. Always verb + noun. Always typed parameters and return types.

```php
// Good
final class CreateUser
final class SendWelcomeEmail
final class ChargeSubscription
final class GenerateInvoicePdf
final class DeactivateAccount
final class SyncProductCatalog

// Bad — nouns without verbs, ambiguous intent
final class UserCreator
final class InvoicePdfHandler
final class SubscriptionCharge
```

### When Actions Beat Services

| Situation | Prefer |
|---|---|
| Single discrete operation (create one resource) | Action |
| Operation that must run async without a separate Job class | Action with `AsAction` |
| Operation reused across 3+ controllers with shared dependencies | Service |
| Multi-step workflow: reserve inventory + create order + notify | Service |
| Needs to run as an Artisan command for operations/seeding | Action with `AsAction` |

### Action vs Job

| | Action | Job |
|---|---|---|
| Sync execution | Yes (`handle()` / `::run()`) | No — always async |
| Async execution | Yes (with `AsAction` trait) | Yes |
| Artisan command | Yes (with `AsAction` trait) | No |
| Base class | None (plain PHP) | Extends nothing but uses `Queueable` trait |

With `lorisleiva/laravel-actions`, a Job class is never needed — the action covers both cases.

### Dispatch Patterns

- `::run()` — resolves the action from the container and calls `handle()` synchronously, returning the result.
- `::dispatch()` — pushes the action onto the queue as a job; returns immediately.
- `::make()` — resolves the action from the container **without** executing it. Useful for passing to `Bus::batch()`, testing with mocked dependencies, or deferring execution.

```php
// Sync — blocks current request, returns result
$payment = ChargeSubscription::run($subscription);

// Async — immediately returns, runs on queue worker
ChargeSubscription::dispatch($subscription);

// Async with delay
ChargeSubscription::dispatch($subscription)->delay(now()->addMinutes(5));

// Async on specific queue
ChargeSubscription::dispatch($subscription)->onQueue('critical');

// Batch (Laravel Bus batch) — use makeJob() so the lorisleiva/laravel-actions
// JobDecorator wraps the call with the runtime parameters. Constructing
// ChargeSubscription directly would require its actual constructor args
// (PaymentGateway), not the handle() arguments.
Bus::batch([
    ChargeSubscription::makeJob($subscription1),
    ChargeSubscription::makeJob($subscription2),
])->dispatch();

// Resolve from container without executing — useful for batching, testing, or deferred execution
$action = CreateUser::make();
$result = $action->handle($data);
```

### Testing Actions

No test doubles, no HTTP stack, no artisan — just call `handle()` directly or use `::run()`:

```php
use App\Actions\Users\CreateUser;
use App\Data\CreateUserData;

it('creates a user with a hashed password', function (): void {
    $data = CreateUserData::from([
        'name'     => 'Jane Doe',
        'email'    => 'jane@example.com',
        'password' => 'secret123',
    ]);

    $user = app(CreateUser::class)->handle($data);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->email)->toBe('jane@example.com')
        ->and(Hash::check('secret123', $user->password))->toBeTrue();
});
```

For actions with external dependencies (payment gateways, mailers), inject a fake via the container:

```php
it('dispatches as a job without executing synchronously', function (): void {
    Queue::fake();

    ChargeSubscription::dispatch($subscription);

    Queue::assertPushed(ChargeSubscription::class, function ($job) use ($subscription) {
        return $job->subscription->is($subscription);
    });
});
```

### Organizing Actions

Group by domain noun inside `app/Actions/`:

```
app/
  Actions/
    Users/
      CreateUser.php
      DeactivateUser.php
      SendPasswordResetEmail.php
    Billing/
      ChargeSubscription.php
      VoidInvoice.php
      IssueRefund.php
    Products/
      SyncProductCatalog.php
      PublishProduct.php
      ArchiveProduct.php
```

For domain-structured applications, move actions inside the domain folder:

```
app/
  Domains/
    Billing/
      Actions/
        ChargeSubscription.php
        IssueRefund.php
      Models/
        Subscription.php
        Payment.php
```
