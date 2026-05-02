# Form Requests — Reference

## Complete Form Request Anatomy

Generate a Form Request with:

```bash
php artisan make:request StorePostRequest
```

A fully-featured Form Request class:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title'      => ['required', 'string', 'max:255'],
            'body'       => ['required', 'string'],
            'status'     => ['required', Rule::in(['draft', 'published'])],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'published_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'      => 'A post title is required.',
            'category_id.exists'  => 'The selected category does not exist.',
        ];
    }

    public function attributes(): array
    {
        return [
            'category_id'  => 'category',
            'published_at' => 'publication date',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim($this->string('title')->value()),
            'slug'  => str($this->string('title')->value())->slug()->value(),
        ]);
    }
}
```

## authorize() — Real Policy Checks

The `authorize()` method must do a real authorization check. Never ship `return true` in production code.

Check a policy action:

```php
public function authorize(): bool
{
    return $this->user()?->can('create', Post::class) ?? false;
}
```

Check ownership of a route-bound model:

```php
public function authorize(): bool
{
    return $this->user()?->can('update', $this->route('post')) ?? false;
}
```

Gate check with additional context:

```php
public function authorize(): bool
{
    return $this->user()?->can('publish', [Post::class, $this->route('post')]) ?? false;
}
```

Returning `false` from `authorize()` is not silent — Laravel automatically calls `failedAuthorization()`, which throws an `AuthorizationException` and produces a 403 response. Throw `AuthorizationException` manually only when you need a custom exception message or a specific response shape that differs from the default 403.

## prepareForValidation()

`prepareForValidation()` runs before the validation rules are applied. Use it to normalize input, not to perform business logic.

Trim and slug a title:

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'title' => trim($this->string('title')->value()),
        'slug'  => str($this->string('title')->value())->slug()->value(),
    ]);
}
```

Cast a string to integer:

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'quantity' => (int) $this->input('quantity', 0),
    ]);
}
```

Convert a comma-separated string to an array:

```php
protected function prepareForValidation(): void
{
    if ($this->has('tags') && is_string($this->tags)) {
        $this->merge([
            'tags' => array_filter(array_map('trim', explode(',', $this->tags))),
        ]);
    }
}
```

## after() — Cross-Field Validation

Use `after()` for validation logic that involves comparing multiple fields or requires state that cannot be expressed in rule syntax.

```php
use Illuminate\Validation\Validator;

public function after(): array
{
    return [
        function (Validator $validator): void {
            if ($this->date('end_date') <= $this->date('start_date')) {
                $validator->errors()->add(
                    'end_date',
                    'The end date must be after the start date.',
                );
            }
        },
    ];
}
```

Multiple closures in `after()` all run even if earlier ones add errors:

```php
public function after(): array
{
    return [
        function (Validator $validator): void {
            // first check
        },
        function (Validator $validator): void {
            // second check — always runs
        },
    ];
}
```

## Custom Rule Classes

Generate a reusable rule class:

```bash
php artisan make:rule ValidIban
```

```php
<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->checkIban((string) $value)) {
            $fail("The :attribute is not a valid IBAN.");
        }
    }

    private function checkIban(string $iban): bool
    {
        // validation logic
        return strlen($iban) >= 15;
    }
}
```

Apply in rules:

```php
'iban' => ['required', 'string', new ValidIban()],
```

Use an inline closure only for one-off rules that have no reuse potential:

```php
'code' => [
    'required',
    function (string $attribute, mixed $value, Closure $fail): void {
        if (! str_starts_with((string) $value, 'PRJ-')) {
            $fail("The {$attribute} must begin with PRJ-.");
        }
    },
],
```

## Accessing Validated Data

`$request->validated()` — returns only the fields that passed validation:

```php
$data = $request->validated();
// ['title' => '...', 'body' => '...', ...]
```

`$request->safe()->only([...])` — returns a subset of validated fields:

```php
$data = $request->safe()->only(['title', 'body']);
```

`$request->safe()->except([...])` — returns validated fields minus excluded keys:

```php
$data = $request->safe()->except(['_token', 'terms']);
```

Always pass `$request->validated()` (or a `safe()` subset) to services. Never pass `$request->all()` or the full `$request` object to a service or action class.

## Conditional Rules

`Rule::when()` — apply a rule only when a condition is met:

```php
'discount' => [
    'nullable',
    Rule::when(
        $this->boolean('has_discount'),
        ['required', 'numeric', 'min:0', 'max:100'],
    ),
],
```

`required_if` — require a field based on another field's value:

```php
'reason' => ['required_if:status,rejected', 'string', 'max:500'],
```

`required_unless` — require a field unless another field has a specific value:

```php
'company_name' => ['required_unless:account_type,personal', 'string', 'max:255'],
```

`sometimes` — only validate a field if it is present in the input:

```php
'phone' => ['sometimes', 'required', 'string', 'regex:/^\+\d{7,15}$/'],
```

## Array and Nested Validation

Validate array items using dot-asterisk notation:

```php
public function rules(): array
{
    return [
        'items'                  => ['required', 'array', 'min:1'],
        'items.*.product_id'     => ['required', 'integer', 'exists:products,id'],
        'items.*.quantity'       => ['required', 'integer', 'min:1'],
        'items.*.unit_price'     => ['required', 'numeric', 'min:0'],
    ];
}
```

Custom error messages for array items use the same dot-asterisk notation:

```php
public function messages(): array
{
    return [
        'items.*.product_id.exists' => 'One or more selected products do not exist.',
        'items.*.quantity.min'      => 'Each item quantity must be at least 1.',
    ];
}
```

## stopOnFirstFailure

Set the `$stopOnFirstFailure` property when failing one rule should stop the entire validation cycle — useful when subsequent rules depend on a preceding rule passing. `FormRequest` reads this as a property; defining it as a method does nothing.

```php
protected $stopOnFirstFailure = true;
```

Use sparingly. In most cases, returning all errors at once provides a better API consumer experience.

## Testing Form Requests

Form Requests are invoked automatically when the route is hit in tests. Use `postJson()`, `putJson()`, and `patchJson()`.

Test that valid data passes:

```php
$this->actingAs($user)
     ->postJson('/api/posts', [
         'title'       => 'Valid Title',
         'body'        => 'Content here.',
         'status'      => 'draft',
         'category_id' => $category->id,
     ])
     ->assertCreated()
     ->assertJsonPath('data.title', 'Valid Title');
```

Test that invalid data returns 422:

```php
$this->actingAs($user)
     ->postJson('/api/posts', [
         'title' => '',
     ])
     ->assertUnprocessable()
     ->assertJsonValidationErrors(['title', 'body', 'status', 'category_id']);
```

Test authorization failure returns 403:

```php
$otherUser = User::factory()->create();

$this->actingAs($otherUser)
     ->putJson("/api/posts/{$post->id}", ['title' => 'New'])
     ->assertForbidden();
```

Test the Form Request class in isolation using `$request->validate()` on a manually constructed instance only when route-level testing is impractical — prefer full HTTP tests.
