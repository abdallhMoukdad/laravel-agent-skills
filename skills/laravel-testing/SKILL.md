---
name: laravel-testing
description: This skill should be used when the user asks to "write a test", "create a feature test", "test an endpoint", "add unit tests", "use Pest", "create a factory", "mock a service", "fake a queue", "test authentication", or when writing any kind of test in Laravel 12.
version: 1.0.0
---

## Test Runner

Laravel 12 ships with Pest as the default test runner. Use `it()`, `describe()`, and `expect()` exclusively. Never use PHPUnit's `$this->assert*` methods — they produce verbose, less readable tests and don't leverage Pest's expressive API.

Feature tests live in `tests/Feature/`. Unit tests live in `tests/Unit/`. Wire the base test case in `tests/Pest.php`:

```php
pest()->extend(Tests\TestCase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->in('Unit');
```

---

## Feature Tests for HTTP Endpoints

Test the full HTTP stack using `$this->getJson()`, `$this->postJson()`, `$this->putJson()`, `$this->patchJson()`, and `$this->deleteJson()`. Create one test file per controller: `tests/Feature/PostControllerTest.php` for `PostController`.

Always assert both the response status AND the response shape — never assert status alone.

```php
it('returns a paginated list of posts', function () {
    Post::factory()->count(5)->create();

    $this->getJson('/api/posts')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'title', 'body']],
            'meta' => ['current_page', 'total'],
        ])
        ->assertJsonCount(5, 'data');
});

it('creates a post and returns 201', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['title' => 'Hello', 'body' => 'World'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Hello');
});
```

---

## Unit Tests for Services and Actions

Unit tests are isolated, contain no database hits, and run fast. Test services and action classes directly by instantiating them or resolving from the container. Mock dependencies via constructor injection using Mockery or `$this->mock()`.

```php
it('calculates the correct invoice total', function () {
    $service = new InvoiceCalculator();

    $result = $service->calculate(items: [
        ['price' => 100, 'qty' => 2],
        ['price' => 50,  'qty' => 1],
    ]);

    expect($result)->toBe(250);
});

it('sends a welcome email after user creation', function () {
    Mail::fake();

    $mailer = app(Mailer::class);
    $mock   = $this->mock(UserRepository::class);
    $mock->shouldReceive('create')->once()->andReturn(User::factory()->make());

    app(CreateUserAction::class)->run(['name' => 'Ada', 'email' => 'ada@test.com']);

    Mail::assertSent(WelcomeEmail::class);
});
```

---

## Factories

Never use raw `User::create(['name' => 'John'])` arrays in tests. Factories handle defaults, relationships, and states cleanly.

Use `make()` when a model instance is needed without database persistence. Use `create()` when the record must exist in the database. Use `count()` for collections.

```php
$user  = User::factory()->create();
$draft = Post::factory()->unpublished()->for($user)->make();
$team  = User::factory()->count(10)->create();
```

Override specific attributes when the test depends on a known value:

```php
$user = User::factory()->create(['email' => 'specific@test.com']);
```

See `references/factories.md` for the full factory reference including states, sequences, relationships, and `afterCreating()`.

---

## Faking External Services

Call `Mail::fake()`, `Queue::fake()`, `Event::fake()`, `Notification::fake()`, `Storage::fake()`, and `Http::fake()` BEFORE the action that triggers them. Calling fake after the trigger produces false positives and is the primary cause of unreliable tests.

```php
it('dispatches a ProcessInvoice job on checkout', function () {
    Queue::fake();                          // fake first

    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkout', ['cart_id' => 1])
        ->assertOk();

    Queue::assertPushed(ProcessInvoice::class);  // then assert
});
```

See `references/faking.md` for the full faking reference covering Mail, Queue, Event, Notification, Storage, Http, and Bus.

---

## Database Strategy

| Trait                  | Behaviour                                        | Use when                                   |
|------------------------|--------------------------------------------------|--------------------------------------------|
| `RefreshDatabase`      | Wraps each test in a transaction, rolls back     | Most feature tests — safe default          |
| `DatabaseTransactions` | Uses a single transaction per test, rolls back   | Sequential tests with no committed-state dependency |
| `DatabaseMigrations`   | Re-runs all migrations before every test         | **Never** — extremely slow, avoid always   |

Use `RefreshDatabase` as the default. Switch to `DatabaseTransactions` when the test suite is large and tests run sequentially.

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a post in the database', function () {
    Post::factory()->create(['title' => 'Test Post']);

    expect(Post::count())->toBe(1);
});
```

---

## Authentication in Tests

Use `$this->actingAs($user)` for web routes. Use `$this->actingAs($user, 'sanctum')` for API routes protected by Laravel Sanctum. Always create the user via factory — never hard-code credentials.

```php
it('allows an admin to delete a post', function () {
    $admin = User::factory()->admin()->create();
    $post  = Post::factory()->create();

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/posts/{$post->id}")
        ->assertNoContent();

    expect(Post::find($post->id))->toBeNull();
});

it('rejects unauthenticated requests', function () {
    $post = Post::factory()->create();

    $this->deleteJson("/api/posts/{$post->id}")
        ->assertUnauthorized();
});
```

---

## Asserting Response Shape

Always verify the response shape, not just the status code.

```php
->assertJsonStructure(['data' => ['id', 'name', 'email']])   // key presence
->assertJsonPath('data.name', 'John')                         // specific value
->assertJsonCount(3, 'data')                                  // collection size
->assertJsonMissing(['password'])                             // sensitive fields absent
```

Combine multiple assertions in a single chain:

```php
$this->getJson("/api/users/{$user->id}")
    ->assertOk()
    ->assertJsonStructure(['data' => ['id', 'name', 'email', 'created_at']])
    ->assertJsonPath('data.email', $user->email)
    ->assertJsonMissing(['password', 'remember_token']);
```

---

## Grouping and Organisation

Use `describe()` to group related tests for a single controller or service. Use `beforeEach()` for shared setup. This keeps test files readable as they grow.

```php
describe('PostController', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    it('lists posts', function () { ... });
    it('creates a post', function () { ... });
    it('rejects unauthorized creation', function () { ... });
});
```

---

## Additional Resources

- `references/pest.md` — Full Pest PHP reference: `it()`, `describe()`, `expect()` chains, datasets, arch tests, higher-order tests, time helpers
- `references/factories.md` — Full factory reference: states, sequences, relationships, `has()`, `for()`, `recycle()`, `afterCreating()`
- `references/faking.md` — Full faking reference: Mail, Queue, Event, Notification, Storage, Http, Bus — with assertion examples and ordering rules
