## Input Security: SQL Injection and Validation

---

## SQL Injection: How It Happens

SQL injection occurs when user-controlled input is concatenated directly into a SQL string. The attacker crafts input that changes the query's intent.

### Classic Injection Scenario

```php
// Vulnerable — attacker controls $search
$results = DB::select("SELECT * FROM users WHERE email = '$email'");
```

Attacker sends `email = "' OR '1'='1"`:

```sql
SELECT * FROM users WHERE email = '' OR '1'='1'
-- Returns ALL users
```

Attacker sends `email = "'; DROP TABLE users; --"`:

```sql
SELECT * FROM users WHERE email = ''; DROP TABLE users; --'
-- Destroys the table
```

### Laravel's Binding Prevention

Laravel's query builder and Eloquent use PDO prepared statements with bound parameters. The user input is **never** interpreted as SQL — it is always treated as a literal value.

```php
// Correct — PDO binding; $email is a value, never SQL
$user = User::where('email', $email)->first();

// Correct — query builder binding
$user = DB::table('users')->where('email', $email)->first();
```

---

## `DB::raw()` — Safe and Unsafe Usage

`DB::raw()` injects a string directly into the SQL without escaping. It is intended for SQL constructs that cannot be expressed through the query builder (e.g., database functions, complex expressions), never for user input.

### Unsafe — Never Do This

```php
// Wrong — user input concatenated into raw SQL
$search = $request->input('search');
$results = DB::select(DB::raw("SELECT * FROM posts WHERE title LIKE '%$search%'"));

// Wrong — column name from user input
$column = $request->input('sort_by');
$posts = Post::orderByRaw("$column DESC")->get();
```

### Safe — Use Bindings

```php
// Correct — DB::select with named binding
$search = $request->validated('search');
$results = DB::select(
    'SELECT * FROM posts WHERE title LIKE :search',
    ['search' => '%' . $search . '%']
);

// Correct — DB::raw() for the expression, binding for the value
$results = DB::table('posts')
    ->selectRaw('id, title, LENGTH(body) as body_length')
    ->whereRaw('LOWER(title) LIKE ?', ['%' . strtolower($search) . '%'])
    ->get();
```

### Safe Column Ordering

Never trust user input for column or direction names. Validate against an explicit allowlist:

```php
$allowedColumns = ['title', 'created_at', 'views'];
$allowedDirections = ['asc', 'desc'];

$column    = in_array($request->input('sort'), $allowedColumns) ? $request->input('sort') : 'created_at';
$direction = in_array($request->input('dir'), $allowedDirections) ? $request->input('dir') : 'desc';

Post::orderBy($column, $direction)->get();
```

---

## `whereRaw()` and `selectRaw()` with Bindings

Both methods accept a second argument for bindings. Always use it.

```php
// whereRaw — positional bindings
Post::whereRaw('views > ? AND status = ?', [$minViews, 'published'])->get();

// whereRaw — named bindings
Post::whereRaw('views > :min AND status = :status', [
    'min'    => $minViews,
    'status' => 'published',
])->get();

// selectRaw — computing values; no user input in the raw string
Post::selectRaw('id, title, ROUND(rating, 2) as rating')->get();

// havingRaw — bindings required when user data is involved
Post::selectRaw('author_id, COUNT(*) as post_count')
    ->groupBy('author_id')
    ->havingRaw('COUNT(*) > ?', [$minCount])
    ->get();
```

---

## Form Request Validation Rules Reference

### Presence and Type

```php
'name'     => ['required', 'string', 'max:255'],
'age'      => ['required', 'integer', 'min:0', 'max:120'],
'price'    => ['required', 'numeric', 'min:0', 'decimal:0,2'],
'active'   => ['required', 'boolean'],
'birthday' => ['required', 'date', 'before:today'],
'file'     => ['required', 'file', 'mimes:pdf,docx', 'max:5120'],  // max KB
'image'    => ['required', 'image', 'dimensions:min_width=100,min_height=100'],
```

### Arrays and Nested Inputs

```php
'tags'    => ['nullable', 'array', 'max:10'],
'tags.*'  => ['string', 'max:50', 'distinct'],

'items'           => ['required', 'array', 'min:1'],
'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:100'],
```

### Database Integrity Checks

```php
// Check the record exists
'category_id' => ['required', 'integer', 'exists:categories,id'],

// Unique on create
'email' => ['required', 'email', 'unique:users,email'],

// Unique on update (ignore the current user's own row)
'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->user()->id)],

// Exists with an additional constraint
'post_id' => [
    'required',
    Rule::exists('posts', 'id')->where('user_id', $this->user()->id),
],
```

### Enum and In-List Validation

```php
use App\Enums\PostStatus;

'status' => ['required', Rule::enum(PostStatus::class)],

// Without an enum
'status' => ['required', 'in:draft,published,archived'],
```

### Conditional and Sometimes Rules

```php
// Only validate if the field is present in the request
'middle_name' => ['sometimes', 'string', 'max:100'],

// Add rules programmatically in withValidator()
public function withValidator(Validator $validator): void
{
    $validator->sometimes('vat_number', ['required', 'string'], function (Fluent $input) {
        return $input->country === 'DE';
    });
}
```

---

## Sanitization vs Validation

**Validate the shape; never sanitize before storing.**

Sanitizing input (stripping tags, encoding) before storing corrupts the stored data and forces you to un-sanitize on read, introducing double-encoding bugs. Store raw validated data; escape on output.

| Context | Correct Approach |
|---|---|
| HTML output in Blade | `{{ $value }}` (auto-escapes) |
| HTML attribute | `{{ $value }}` inside the attribute |
| Raw HTML output | `{!! $value !!}` only with fully trusted content |
| JSON API response | `json_encode` / `response()->json()` — no HTML encoding needed |
| SQL | PDO bindings — never strip or encode before querying |

Never run `htmlspecialchars()` or `strip_tags()` before storing to the database. If you need to allow rich text, validate it on output using a library like HTML Purifier, not before storage.

---

## XSS and Content-Type Headers for API Responses

For pure JSON APIs, always return `Content-Type: application/json`. Laravel does this automatically when using `response()->json()` or returning an `ApiResource`. This prevents browsers from sniffing and rendering the response as HTML.

```php
// Correct — sets Content-Type: application/json automatically
return new PostResource($post);
return response()->json(['message' => 'Created'], 201);

// Wrong — returns a plain string without Content-Type, browser may sniff it
return response($post->body);
```

Add a `X-Content-Type-Options: nosniff` header globally via a small response middleware (or a third-party package such as `bepsvpt/secure-headers`).

```php
// In a middleware or global response macro
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->headers->set('X-Frame-Options', 'DENY');
// Note: X-XSS-Protection is deprecated and removed from modern browsers.
// Modern XSS protection relies on Content-Security-Policy headers instead.
$response->headers->set('Content-Security-Policy', "default-src 'self'");
```

For Blade-rendered applications, always use `{{ }}` (not `{!! !!}`) for any user-controlled content. Use `{!! !!}` exclusively for content that is authored internally and never touches user input.
