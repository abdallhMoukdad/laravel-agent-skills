## Mass Assignment Protection

---

## The Exploit Scenario

Consider a user registration endpoint:

```php
// Vulnerable
User::create($request->all());
```

A malicious user sends:

```json
{
  "name": "Attacker",
  "email": "attacker@example.com",
  "password": "secret",
  "is_admin": true,
  "role": "superadmin",
  "email_verified_at": "2020-01-01 00:00:00"
}
```

Without `$fillable`, all fields including `is_admin`, `role`, and `email_verified_at` are written to the database. This grants the attacker administrative access. This exact class of vulnerability has caused high-profile breaches (GitHub mass assignment incident, 2012).

---

## `$fillable` vs `$guarded`

### `$fillable` — Explicit Whitelist (Recommended)

Only columns listed in `$fillable` can be set via mass assignment. Any column not listed is silently ignored, even if present in the data.

```php
class User extends Model
{
    protected $fillable = ['name', 'email', 'password'];
}
```

Adding a new column (e.g., `stripe_customer_id`) to the database table does **not** automatically make it mass-assignable. It must be explicitly added to `$fillable`. This is the safe default — you opt in per column.

### `$guarded` — Blacklist (Risky)

Only columns listed in `$guarded` are blocked. Everything else is fillable. This is an opt-out approach — any new column you add is immediately mass-assignable.

```php
// Blocks only these two columns — everything else is fillable
class User extends Model
{
    protected $guarded = ['is_admin', 'role'];
}
```

The problem: as the schema grows, new columns are silently included unless they are remembered and added to `$guarded`. This is an ongoing maintenance liability.

### `$guarded = []` — Disables All Protection

This is the most dangerous configuration. It disables mass assignment protection entirely.

```php
// Never use in production models
class User extends Model
{
    protected $guarded = [];
}
```

Never use `$guarded = []` on production models. It is acceptable only in seeders or factories operating on trusted internal data.

### `$guarded = ['*']` — Locks Everything

Setting `$guarded = ['*']` is the opposite: it blocks all columns from mass assignment. Use this on models where you want to ensure nothing is ever mass-assigned and all writes go through explicit attribute setters.

```php
// Zero mass assignment — all writes must be explicit attribute assignments
class AuditLog extends Model
{
    protected $guarded = ['*'];
}
```

---

## Safe Usage of `create()`, `update()`, `fill()`, and `forceFill()`

### `create()`

Passes the array through `$fillable` / `$guarded` filtering before inserting:

```php
// Safe — only fills whitelisted columns
$user = User::create($request->validated());
```

Always pass `$request->validated()` (not `$request->all()`) so validation has run first.

### `update()`

Same filtering as `create()` — applies the mass assignment whitelist:

```php
$user->update($request->validated());
```

### `fill()`

Applies the same `$fillable` / `$guarded` protection. Does not save — call `save()` separately:

```php
$user->fill($request->validated());
$user->save();
```

Useful when you need to conditionally mutate before saving.

### `forceFill()`

Bypasses `$fillable` and `$guarded` entirely. Use only for:

- **Database seeders** populating trusted fixture data
- **Internal admin scripts** where input is not user-controlled
- **Migrations or data fixers** running offline

```php
// Acceptable in a seeder
$user->forceFill([
    'email_verified_at' => now(),
    'is_admin' => true,
])->save();
```

Never pass request data to `forceFill()`. Even data that appears internal (e.g., from a webhook) must go through `fill()` with a `$fillable` whitelist.

---

## Recommended Default for New Models

When generating a model, immediately add `$fillable` before writing any controller code. Start with an empty array and add columns explicitly as needed:

```php
class Post extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'body',
        'status',
        'published_at',
    ];

    protected $hidden = [
        'deleted_at',
    ];
}
```

Do not leave `$fillable` and `$guarded` both undefined — Laravel's default when neither is set is to guard all fields (equivalent to `$guarded = ['*']`), but this is implementation-dependent and should not be relied upon. Always be explicit.

---

## Checking What Is Fillable

```php
// Inspect at runtime (useful in tinker)
$user = new User;
$user->getFillable();   // returns the $fillable array
$user->getGuarded();    // returns the $guarded array

// Check if a specific attribute is fillable
$user->isFillable('is_admin');  // false if not in $fillable
$user->isGuarded('is_admin');   // true if in $guarded
```

---

## Summary

| Configuration | Behavior | Production Safe? |
|---|---|---|
| `$fillable = ['col1', 'col2']` | Whitelist — only listed columns fillable | Yes — recommended |
| `$guarded = ['col1']` | Blacklist — all except listed | Risky |
| `$guarded = []` | No protection — everything fillable | Never |
| `$guarded = ['*']` | Everything blocked | Yes — for audit/log models |
| Neither defined | Technically `$guarded = ['*']` in Laravel 9+ — but implementation-dependent; always be explicit. | Acceptable but be explicit |
