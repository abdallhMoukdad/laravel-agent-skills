## Repositories and DTOs Reference

### Repository Pattern — When It's Justified

The repository pattern adds a layer between services and the database. Skip it for most Laravel applications — Eloquent with query scopes covers the majority of use cases.

**Justified when:**
- The data source may change (Eloquent today, external API tomorrow)
- Complex query composition is shared across many services and must be tested independently
- Tests need to swap the implementation through the container without touching the database

**Skip it when:**
- The application only ever uses Eloquent
- Queries are simple and scopes handle reuse well
- The overhead of an interface + implementation + binding slows delivery without measurable benefit

```php
// Most apps — no repository, just scopes
$users = User::active()->verified()->orderByName()->paginate(20);
```

### Repository Interface + Eloquent Implementation

Define the interface in `app/Contracts/` and the implementation in `app/Repositories/`:

```php
<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\CreateUserData;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function create(CreateUserData $data): User;

    public function paginate(int $perPage = 20): LengthAwarePaginator;

    public function deactivate(User $user): void;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\UserRepositoryInterface;
use App\Data\CreateUserData;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private readonly Hasher $hasher,
    ) {}

    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function create(CreateUserData $data): User
    {
        return User::create([
            'name'     => $data->name,
            'email'    => $data->email,
            'password' => $this->hasher->make($data->password),
        ]);
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return User::active()->verified()->orderBy('name')->paginate($perPage);
    }

    public function deactivate(User $user): void
    {
        $user->update(['active' => false, 'deactivated_at' => now()]);
    }
}
```

**Bind in `AppServiceProvider`:**

```php
public function register(): void
{
    $this->app->bind(
        \App\Contracts\UserRepositoryInterface::class,
        \App\Repositories\EloquentUserRepository::class,
    );
}
```

**Usage in a service:**

```php
final class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function createUser(CreateUserData $data): User
    {
        if ($this->users->findByEmail($data->email)) {
            throw new EmailAlreadyTakenException($data->email);
        }

        return $this->users->create($data);
    }
}
```

**Swap for tests via the container:**

```php
// In a test base class or individual test
$this->app->bind(UserRepositoryInterface::class, InMemoryUserRepository::class);
```

### DTOs with `spatie/laravel-data`

DTOs (Data Transfer Objects) replace untyped arrays as the data contract between layers.

```bash
composer require spatie/laravel-data
```

**Define a DTO:**

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class CreateUserData extends Data
{
    public function __construct(
        #[Max(255)]
        public readonly string $name,

        #[Email, Max(255)]
        public readonly string $email,

        #[Min(8), Max(128)]
        public readonly string $password,
    ) {}
}
```

**Create from a validated request:**

```php
$data = CreateUserData::from($request->validated());
```

`spatie/laravel-data` maps array keys to constructor parameters automatically. No manual assignment.

**Create from a model (auto-maps attributes):**

```php
$data = UserData::from($user);
```

**Nested DTOs:**

```php
final class OrderData extends Data
{
    public function __construct(
        public readonly int $userId,
        public readonly AddressData $shippingAddress,
        /** @var DataCollection<int, OrderItemData> */
        public readonly DataCollection $items,
        public readonly ?string $promoCode,
    ) {}
}
```

**Collections of DTOs:**

```php
$items = OrderItemData::collection($request->validated()['items']);
```

**Serialization:**

```php
$data->toArray();   // PHP array
$data->toJson();    // JSON string

// Also works with ->include() for optional fields
$data->include('nested.relation')->toArray();
```

**Built-in validation** — use the DTO directly as a Form Request replacement:

```php
// In a controller
public function store(Request $request): JsonResponse
{
    $data = CreateUserData::validateAndCreate($request->all());
    // Throws ValidationException automatically if rules fail
}
```

**TypeScript generation** (optional, via `spatie/laravel-typescript-transformer`):

```bash
php artisan typescript:transform
```

Generates TypeScript interfaces matching every DTO — keeps frontend types in sync.

### Value Objects

Value objects represent domain concepts as immutable PHP objects. Use them when a raw scalar (string, int) has domain rules or can be confused with other scalars.

**When to use value objects:**
- A concept has constraints: `EmailAddress` must be a valid email, `Money` must have a non-negative amount
- Two scalars of the same type are easily confused: `$cents` vs `$dollars`, `$userId` vs `$orderId`
- The concept has behaviour: `Money::add()`, `Money::convertTo()`

**When to stay with scalars:**
- Simple strings or integers with no behaviour or domain constraint
- Performance-critical hot paths where object allocation matters

**Defining a value object:**

```php
<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public readonly int $amount,       // always in cents
        public readonly string $currency,  // ISO 4217, e.g. 'USD'
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException("Money amount cannot be negative: {$amount}");
        }

        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Currency must be a 3-character ISO code: {$currency}");
        }
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot add {$this->currency} and {$other->currency}."
            );
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function format(): string
    {
        return number_format($this->amount / 100, 2) . ' ' . $this->currency;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
```

**Other common value objects:**

```php
final readonly class EmailAddress
{
    public function __construct(public readonly string $value)
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email address: {$value}");
        }
    }
}

final readonly class PhoneNumber
{
    public function __construct(public readonly string $value)
    {
        // E.164 format: +15551234567
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $value)) {
            throw new InvalidArgumentException("Invalid E.164 phone number: {$value}");
        }
    }
}
```

**Store value objects in Eloquent using casts:**

```php
// In the model
protected $casts = [
    'price' => MoneyCast::class,
    'email' => EmailAddressCast::class,
];
```

```php
final class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return new Money(
            amount: (int) $attributes['price_cents'],
            currency: $attributes['price_currency'],
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        assert($value instanceof Money);

        return [
            'price_cents'    => $value->amount,
            'price_currency' => $value->currency,
        ];
    }
}
```
