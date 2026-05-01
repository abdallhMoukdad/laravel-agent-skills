---
name: laravel-architecture
description: This skill should be used when the user asks to "structure business logic", "create a service class", "where should this logic go", "organize the app folder", "create an action", "avoid fat controllers", "extract logic from controller", or when designing the architecture of a Laravel 12 application.
version: 1.0.0
---

## The Controller Contract

A controller method does exactly three things: receive a validated Form Request, call one service or action, return a Resource. Nothing else.

```php
public function store(StoreOrderRequest $request): JsonResponse
{
    $order = $this->orderService->createOrder(
        OrderData::from($request->validated())
    );

    return OrderResource::make($order)
        ->response()
        ->setStatusCode(201);
}
```

No `if` chains. No DB queries. No business decisions. No emails. No job dispatches. If a controller method exceeds ~10 lines, extract to a service or action.

Controllers never know how business logic is implemented. They only know what to call and what to return.

## Services — Multi-Step Orchestration

Use a service when the operation involves multiple models, external API calls, or logic that multiple controllers share.

**One service per domain area:** `UserService`, `OrderService`, `InvoiceService`.

Always inject via constructor — never `new UserService()` inside a controller or another class.

```php
final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly InventoryService $inventory,
        private readonly Mailer $mailer,
    ) {}

    public function createOrder(OrderData $data): Order
    {
        $this->inventory->reserve($data->items);

        $order = Order::create($data->toArray());

        $order->items()->createMany($data->items->toArray());

        $this->mailer->send(new OrderConfirmation($order));

        return $order;
    }
}
```

**Services are synchronous.** Never put `dispatch()` calls inside a service — that belongs in the controller or an action. Mixing sync and async concerns inside a service creates hidden side effects.

**Method naming:** verb-noun pairs — `createInvoice()`, `cancelSubscription()`, `applyDiscount()`.

**Error handling:** throw specific domain exceptions on failure. Never return `false` or `null` to signal an error condition.

```php
// Wrong
public function cancelSubscription(Subscription $sub): bool
{
    if ($sub->isCancelled()) {
        return false;
    }
    // ...
    return true;
}

// Correct
public function cancelSubscription(Subscription $sub, string $reason): void
{
    if ($sub->isCancelled()) {
        throw new SubscriptionAlreadyCancelledException($sub->id);
    }
    // ...
}
```

## Actions — Single-Responsibility Operations

An action is a single-purpose class that does one thing. `CreateUser`, `SendWelcomeEmail`, `ChargeSubscription`.

Prefer actions over services when the operation is discrete and testable in isolation.

**Plain action pattern (no package):**

```php
final class CreateUser
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly Hasher $hasher,
    ) {}

    public function handle(CreateUserData $data): User
    {
        return $this->users->create([
            'name'     => $data->name,
            'email'    => $data->email,
            'password' => $this->hasher->make($data->password),
        ]);
    }
}
```

**With `lorisleiva/laravel-actions`**, the same class runs synchronously, as a queued job, or as an Artisan command without duplication:

```php
final class ChargeSubscription
{
    use AsAction;

    public function handle(Subscription $subscription): Payment
    {
        // ...
    }
}

// Sync
ChargeSubscription::run($subscription);

// Async (dispatched as a job)
ChargeSubscription::dispatch($subscription);
```

Actions are easily dispatched as background jobs — no separate Job class needed.

**Decision rule:**

| Situation | Solution |
|---|---|
| One discrete operation | Action |
| Multi-step orchestration shared across controllers | Service |
| Simple CRUD with no business rules | Thin controller + Eloquent directly |
| Model needs to react to its own events | Observer |

## Fat Model Prevention

Models define: `$fillable`, `$casts`, relationships, scopes, and accessors.

Models do NOT: send emails, dispatch jobs, make HTTP calls, or contain `if` business logic.

```php
// Wrong — logic in a model
class Order extends Model
{
    public function cancel(): void
    {
        if ($this->status === 'shipped') {
            Mail::to($this->user)->send(new OrderCannotBeCancelledMail());
            return;
        }
        $this->update(['status' => 'cancelled']);
        event(new OrderCancelled($this));
    }
}

// Correct — model is a data container, logic lives in a service or action
class Order extends Model
{
    protected $fillable = ['status', 'user_id', 'total'];

    protected $casts = ['total' => 'integer', 'placed_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }
}
```

## Dependency Injection

Never use `new ClassName()` inside a controller, service, or another service. Always resolve dependencies from the container via constructor injection.

Laravel auto-resolves concrete classes — explicit binding in `AppServiceProvider` is only needed for interfaces.

```php
// AppServiceProvider — only needed when binding an interface to an implementation
public function register(): void
{
    $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
}
```

## Folder Structure

```
app/
  Actions/
    Users/
      CreateUser.php
      DeactivateUser.php
    Billing/
      ChargeSubscription.php
      VoidInvoice.php
  Services/
    UserService.php
    OrderService.php
    InvoiceService.php
  Data/                        # DTOs via spatie/laravel-data
    UserData.php
    OrderData.php
  Exceptions/                  # Domain exceptions
    InsufficientFundsException.php
    SubscriptionAlreadyCancelledException.php
```

For large applications, use a feature-based domain structure:

```
app/
  Domains/
    Orders/
      Actions/
      Services/
      Models/
      Events/
      Exceptions/
    Billing/
      ...
```

## Quick Decision Reference

- **Is it one discrete operation?** → Action
- **Is it multi-step orchestration shared across controllers?** → Service
- **Is it simple CRUD with no business rules?** → Thin controller + Eloquent directly
- **Does the model need to react to its own lifecycle events?** → Observer
- **Is the operation repeated in tests and needs isolated testing?** → Action
- **Does logic span more than one model or external API?** → Service

## Additional Resources

- [`references/service-layer.md`](references/service-layer.md) — Full service class patterns, error handling, testing, and `AppServiceProvider` bindings
- [`references/actions.md`](references/actions.md) — Plain and `lorisleiva/laravel-actions` patterns, sync vs async dispatch, naming conventions
- [`references/repositories-dtos.md`](references/repositories-dtos.md) — Repository pattern, DTOs with `spatie/laravel-data`, and value objects
