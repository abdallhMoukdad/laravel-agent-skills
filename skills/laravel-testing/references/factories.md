# Laravel Factory Reference

## Factory Class Anatomy

Every factory extends `Illuminate\Database\Eloquent\Factories\Factory`. The `definition()` method returns the default attribute array. Use `configure()` for one-time setup after the factory is instantiated. Use `afterMaking()` and `afterCreating()` for post-construction hooks.

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => Hash::make('password'),
            'remember_token'    => \Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            // runs after every create() call by default
        });
    }
}
```

---

## States

States override specific attributes or attach related data. Chain multiple states together.

```php
public function unverified(): static
{
    return $this->state(fn (array $attributes) => [
        'email_verified_at' => null,
    ]);
}

public function admin(): static
{
    return $this->state(fn (array $attributes) => [
        'role' => 'admin',
    ]);
}

public function suspended(): static
{
    return $this->state(fn (array $attributes) => [
        'suspended_at' => now(),
    ]);
}
```

Usage — states are chainable:

```php
$user = User::factory()->admin()->unverified()->create();
```

---

## `Sequence` — Alternating Values

Use `Sequence` to cycle through a set of values across multiple models:

```php
use Illuminate\Database\Eloquent\Factories\Sequence;

$users = User::factory()->count(4)->state(new Sequence(
    ['role' => 'admin'],
    ['role' => 'editor'],
    ['role' => 'viewer'],
))->create();
// roles: admin, editor, viewer, admin
```

Named sequences with closures:

```php
$posts = Post::factory()->count(6)->sequence(
    fn (Sequence $seq) => ['order' => $seq->index + 1],
)->create();
```

---

## `count()` — Creating Collections

```php
$users = User::factory()->count(10)->create();
$posts = Post::factory()->count(3)->make();   // no DB hit
```

---

## `make()` vs `create()` vs `makeMany()` vs `createMany()`

| Method        | Persisted | Returns              | Use when                                      |
|---------------|-----------|----------------------|-----------------------------------------------|
| `make()`      | No        | Model instance       | Unit tests, no DB needed                      |
| `create()`    | Yes       | Model instance       | Feature tests that query the DB               |
| `makeMany()`  | No        | Collection           | Multiple in-memory models                     |
| `createMany()`| Yes       | Collection           | Seeding or tests needing multiple DB records  |

```php
$user  = User::factory()->make();
$users = User::factory()->makeMany(5);      // Collection of 5, not persisted
$users = User::factory()->createMany([      // Array of attribute overrides
    ['name' => 'Ada'],
    ['name' => 'Grace'],
]);
```

---

## `has()` — hasMany and hasOne Relationships

```php
// User with 3 posts
$user = User::factory()
    ->has(Post::factory()->count(3))
    ->create();

// Magic method alias — reads as "withPosts"
$user = User::factory()
    ->hasPosts(3)
    ->create();

// With attribute overrides on related models
$user = User::factory()
    ->has(Post::factory()->count(2)->state(['published' => true]))
    ->create();
```

---

## `for()` — belongsTo Relationships

```php
$post = Post::factory()->for($user)->create();

// Or create the parent inline
$post = Post::factory()->for(User::factory()->admin())->create();

// Magic alias
$post = Post::factory()->forUser($user)->create();
```

---

## Nested Relationships

```php
$user = User::factory()
    ->has(
        Post::factory()
            ->count(3)
            ->has(
                Comment::factory()->count(2)
            )
    )
    ->create();
```

This produces 1 user, 3 posts, each with 2 comments — 6 comments total.

---

## Overriding Specific Attributes

Pass an array to `create()` or `make()` to override specific fields:

```php
$user = User::factory()->create([
    'email' => 'known@test.com',
    'name'  => 'Ada Lovelace',
]);
```

Attribute overrides passed to `create()` always win over factory defaults and states.

---

## `raw()` — Array Without Model Creation

Returns a plain PHP array of attributes without instantiating or persisting a model. Useful for building request payloads in HTTP tests:

```php
$payload = User::factory()->raw();

$this->postJson('/api/users', $payload)->assertCreated();
```

---

## `recycle()` — Reuse an Existing Related Model

When multiple factories would otherwise each create their own copy of a shared related model, `recycle()` passes the same existing model to all of them:

```php
$team  = Team::factory()->create();
$users = User::factory()->count(5)->recycle($team)->create();
// All 5 users belong to the same $team — no extra Team rows created
```

`recycle()` accepts a single model or a collection.

---

## `afterMaking()` and `afterCreating()`

`afterMaking()` runs after `make()`. `afterCreating()` runs after `create()`. Use them for setup that requires the model's ID or relationships that cannot be expressed as a simple attribute override.

```php
public function withRoles(): static
{
    return $this->afterCreating(function (User $user) {
        $user->roles()->attach(Role::where('name', 'editor')->firstOrCreate(['name' => 'editor']));
    });
}
```

```php
$user = User::factory()->withRoles()->create();
```

`afterCreating()` is also the right place to generate related records that reference the parent's ID in a way that `has()` does not support — for example, attaching polymorphic models or calling external setup methods.

---

## Putting It All Together

```php
describe('PostController', function () {
    it('returns posts for the authenticated user', function () {
        $user  = User::factory()->create();
        $posts = Post::factory()->count(3)->for($user)->create();

        // another user's post — must not appear in the response
        Post::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/posts')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });
});
```
