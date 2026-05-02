# Pest PHP Reference

## `it()` vs `test()`

Both `it()` and `test()` register a test. Prefer `it()` — it reads as a sentence describing behaviour:

```php
it('creates a user with hashed password');
test('user password is hashed on creation');
```

Both are equivalent; `it()` is idiomatic in Laravel Pest projects.

---

## `describe()` — Grouping Related Tests

Use `describe()` to group tests for a single class, endpoint, or behaviour:

```php
describe('UserController', function () {
    it('returns a list of users', function () { ... });
    it('returns 404 for unknown user', function () { ... });
    it('rejects unauthenticated requests', function () { ... });
});
```

Nested `describe()` blocks are allowed:

```php
describe('PostController', function () {
    describe('store', function () {
        it('creates a post', function () { ... });
        it('validates required fields', function () { ... });
    });

    describe('destroy', function () {
        it('deletes a post', function () { ... });
        it('rejects non-owners', function () { ... });
    });
});
```

---

## `beforeEach()` and `afterEach()`

`beforeEach()` runs before every test in the current scope. `afterEach()` runs after every test.

```php
describe('InvoiceService', function () {
    beforeEach(function () {
        $this->user    = User::factory()->create();
        $this->invoice = Invoice::factory()->for($this->user)->create();
    });

    afterEach(function () {
        // clean up external resources if needed
    });

    it('marks the invoice as paid', function () {
        app(InvoiceService::class)->markPaid($this->invoice);
        expect($this->invoice->fresh()->status)->toBe('paid');
    });
});
```

---

## `expect()` Chains

### Equality and Identity

```php
expect($value)->toBe(42);              // strict identity (===)
expect($value)->toEqual([1, 2, 3]);    // loose equality (==)
expect($value)->not->toBe(0);          // negation
```

### Boolean and Null

```php
expect($result)->toBeTrue();
expect($result)->toBeFalse();
expect($result)->toBeNull();
expect($result)->not->toBeNull();
```

### Strings and Arrays

```php
expect('hello world')->toContain('world');
expect(['a', 'b', 'c'])->toContain('b');
expect(['id' => 1, 'name' => 'Ada'])->toHaveKey('name');
expect(['id' => 1, 'name' => 'Ada'])->toHaveKey('name', 'Ada');
expect($collection)->toHaveCount(3);
```

### Types

```php
expect($user)->toBeInstanceOf(User::class);
expect($value)->toBeString();
expect($value)->toBeInt();
expect($value)->toBeArray();
```

### Exceptions

```php
expect(fn () => $service->run(null))->toThrow(InvalidArgumentException::class);
expect(fn () => $service->run(null))->toThrow(InvalidArgumentException::class, 'ID cannot be null');
```

### Multiple Assertions on One Value

```php
expect($user)
    ->name->toBe('Ada')
    ->email->toContain('@')
    ->created_at->not->toBeNull();
```

---

## Negation

Prefix any expectation with `->not->` to invert it:

```php
expect($user)->not->toBeNull();
expect($list)->not->toContain('banned-value');
expect($result)->not->toBeInstanceOf(ErrorResponse::class);
```

---

## Custom Expectations

Extend `expect()` with domain-specific helpers in `tests/Pest.php` or `tests/Helpers.php`:

```php
expect()->extend('toBeActiveUser', function () {
    return $this->toBeInstanceOf(User::class)
                ->active->toBeTrue()
                ->banned_at->toBeNull();
});

// Usage
expect($user)->toBeActiveUser();
```

---

## Datasets

### Inline Datasets

```php
it('rejects invalid emails', function (string $email) {
    expect(fn () => new Email($email))->toThrow(InvalidEmailException::class);
})->with([
    'not-an-email',
    'missing@',
    '@nodomain.com',
]);
```

### Named Datasets

```php
dataset('invalid_emails', [
    'no-at-sign'    => ['not-an-email'],
    'missing-local' => ['@nodomain.com'],
    'missing-tld'   => ['user@'],
]);

it('rejects invalid emails', function (string $email) {
    expect(fn () => new Email($email))->toThrow(InvalidEmailException::class);
})->with('invalid_emails');
```

### Multiple Parameters

```php
it('converts currencies', function (int $amount, string $from, string $to, int $expected) {
    expect(convert($amount, $from, $to))->toBe($expected);
})->with([
    [100, 'USD', 'EUR', 92],
    [200, 'GBP', 'USD', 254],
]);
```

---

## Higher-Order Tests

Access properties and call methods directly on the `expect()` value via property chaining:

```php
expect($user)
    ->name->toBe('Ada Lovelace')
    ->email->toContain('@')
    ->roles->toHaveCount(2);

expect($response)
    ->status()->toBe(200)
    ->json('data.name')->toBe('Ada');
```

---

## Arch Tests

Arch tests enforce architectural rules across the entire codebase. Run them once — they catch structural drift without manual review.

```php
arch()->preset()->laravel();   // enforces standard Laravel conventions
```

Custom arch rules:

```php
arch('models are classes')
    ->expect('App\Models')
    ->toBeClasses();

arch('controllers do not use Eloquent directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Database\Eloquent\Model');

arch('actions are final')
    ->expect('App\Actions')
    ->toBeFinal();

arch('value objects are readonly')
    ->expect('App\ValueObjects')
    ->toBeReadonly();

arch('no debug functions in production code')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray']);
```

---

## `tests/Pest.php` Configuration

Wire the base test case, define global uses, and register helpers:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()
    ->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()
    ->extend(Tests\TestCase::class)
    ->in('Unit');
```

---

## Custom Helpers

Define helper functions available to all tests in `tests/Pest.php` or a dedicated `tests/Helpers.php`:

```php
// tests/Pest.php
function actingAsAdmin(): Tests\TestCase
{
    $admin = User::factory()->admin()->create();
    return test()->actingAs($admin, 'sanctum');
}

function createPostWithComments(int $count = 3): Post
{
    return Post::factory()
        ->has(Comment::factory()->count($count))
        ->create();
}
```

Include `tests/Helpers.php` by requiring it from `Pest.php` or via `autoload-dev` in `composer.json`.

---

## Time Helpers

Freeze time or travel to a specific point for time-sensitive tests:

```php
it('expires tokens after 24 hours', function () {
    $token = PersonalAccessToken::factory()->create([
        'created_at' => now(),
    ]);

    $this->travelTo(now()->addHours(25));

    expect($token->isExpired())->toBeTrue();
});

it('sends a reminder on the correct day', function () {
    $this->freezeTime();

    $reminder = Reminder::factory()->create(['send_at' => now()]);
    SendReminders::dispatch();

    Notification::assertSentTo($reminder->user, ReminderNotification::class);
});
```

`$this->travelBack()` resets the clock to real time after travelling.
