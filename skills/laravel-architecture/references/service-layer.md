## Service Layer Reference

### Full Service Class Structure

Use PHP 8.2+ `readonly` constructor promotion. Define one public method per use case. Keep private helpers small and focused.

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\InvoiceData;
use App\Data\VoidInvoiceData;
use App\Exceptions\InvoiceAlreadyPaidException;
use App\Exceptions\InvoiceAlreadyVoidedException;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class InvoiceService
{
    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly PdfGenerator $pdfGenerator,
        private readonly NumberGenerator $numberGenerator,
    ) {}

    public function createInvoice(Order $order, InvoiceData $data): Invoice
    {
        return DB::transaction(function () use ($order, $data): Invoice {
            $invoice = Invoice::create([
                'order_id'   => $order->id,
                'number'     => $this->numberGenerator->next(),
                'subtotal'   => $data->subtotal,
                'tax'        => $this->taxCalculator->calculate($data->subtotal, $data->taxRate),
                'total'      => $data->subtotal + $this->taxCalculator->calculate($data->subtotal, $data->taxRate),
                'issued_at'  => now(),
                'due_at'     => $data->dueAt,
            ]);

            $this->pdfGenerator->generateForInvoice($invoice);

            return $invoice->fresh();
        });
    }

    public function voidInvoice(Invoice $invoice, VoidInvoiceData $data): void
    {
        if ($invoice->isVoided()) {
            throw new InvoiceAlreadyVoidedException($invoice->id);
        }

        if ($invoice->isPaid()) {
            throw new InvoiceAlreadyPaidException(
                "Invoice #{$invoice->number} is paid and cannot be voided without a refund."
            );
        }

        $invoice->update([
            'status'    => 'voided',
            'voided_at' => now(),
            'void_reason' => $data->reason,
        ]);
    }

    private function formatNumber(int $sequence): string
    {
        return 'INV-' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
```

### When to Use vs Skip a Service

**Use a service when:**
- The operation touches more than one model
- Logic is reused by multiple controllers or commands
- An external API call is involved alongside DB writes
- Multiple steps must succeed or fail together (wrap in a `DB::transaction`)

**Skip a service when:**
- The endpoint is pure CRUD: create one model, return it
- No business rules apply beyond validation
- The operation is a single Eloquent call

```php
// Simple CRUD — no service needed
public function store(StoreTagRequest $request): JsonResponse
{
    $tag = Tag::create($request->validated());

    return TagResource::make($tag)->response()->setStatusCode(201);
}
```

### Method Naming Conventions

Service methods are named as verb-noun pairs. The verb describes the intent, not the implementation.

```php
// Good
public function createUser(CreateUserData $data): User {}
public function cancelSubscription(Subscription $sub, string $reason): void {}
public function applyPromoCode(Order $order, string $code): Discount {}
public function transferFunds(Account $from, Account $to, Money $amount): Transfer {}

// Bad — implementation detail in the name
public function insertUserIntoDatabase(array $data): User {}
public function setSubscriptionStatusToCancelled(int $id): void {}
```

### Error Handling

Throw specific domain exceptions. Never return `false`, `null`, `-1`, or an empty array to signal that an operation failed.

Define domain exceptions in `app/Exceptions/`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            "Account {$accountId} has insufficient funds. Requested: {$requested}, available: {$available}."
        );
    }
}
```

Catch and convert domain exceptions to HTTP responses inside a handler or controller — not inside the service:

```php
// In bootstrap/app.php (Laravel 11+)
$exceptions->render(function (InsufficientFundsException $e, Request $request) {
    return response()->json([
        'message' => $e->getMessage(),
        'code'    => 'insufficient_funds',
    ], 422);
});
```

### Services vs Jobs

| | Service | Job |
|---|---|---|
| Execution | Synchronous, in the current request | Asynchronous, in a queue worker |
| Purpose | Coordinate business logic | Defer work out of the request cycle |
| Dispatch | Never — services are called directly | Via `dispatch()` or `Bus::dispatch()` |

Never call `dispatch()` inside a service. If an operation must trigger a background job, dispatch from the controller or from an action after the service returns.

```php
// Wrong — async dispatch hidden inside synchronous service
public function createUser(CreateUserData $data): User
{
    $user = User::create([...]);
    SendWelcomeEmail::dispatch($user); // hidden async side effect
    return $user;
}

// Correct — controller decides what happens after
public function store(StoreUserRequest $request): JsonResponse
{
    $user = $this->userService->createUser(
        CreateUserData::from($request->validated())
    );

    SendWelcomeEmail::dispatch($user);

    return UserResource::make($user)->response()->setStatusCode(201);
}
```

### Testing Services

Constructor-inject fakes or mocks. Test the public method in isolation. No HTTP, no artisan, no test database required for pure logic.

```php
use App\Services\InvoiceService;
use App\Data\InvoiceData;
use Tests\Fakes\FakeTaxCalculator;
use Tests\Fakes\FakePdfGenerator;
use Tests\Fakes\FakeNumberGenerator;

it('creates an invoice with correct totals', function (): void {
    $service = new InvoiceService(
        taxCalculator: new FakeTaxCalculator(tax: 20_00),
        pdfGenerator: new FakePdfGenerator(),
        numberGenerator: new FakeNumberGenerator(next: 1),
    );

    $order = Order::factory()->create();
    $data  = InvoiceData::from(['subtotal' => 100_00, 'tax_rate' => 0.2, 'due_at' => now()->addDays(30)]);

    $invoice = $service->createInvoice($order, $data);

    expect($invoice->total)->toBe(120_00);
    expect($invoice->number)->toBe('INV-000001');
});
```

### Auto-Resolution vs Explicit Binding

Laravel resolves concrete classes automatically — no binding required.

```php
// No binding needed in AppServiceProvider — Laravel resolves this automatically
public function store(StoreOrderRequest $request, OrderService $service): JsonResponse {}
```

Bind in `AppServiceProvider` only when a type hint is an interface:

```php
// AppServiceProvider
public function register(): void
{
    $this->app->bind(
        \App\Contracts\TaxCalculatorInterface::class,
        \App\Services\VatTaxCalculator::class,
    );

    // Swap implementation for a specific environment
    if ($this->app->environment('testing')) {
        $this->app->bind(
            \App\Contracts\PdfGeneratorInterface::class,
            \Tests\Fakes\FakePdfGenerator::class,
        );
    }
}
```
