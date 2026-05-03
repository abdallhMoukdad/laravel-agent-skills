---
name: laravel-localization
description: This skill should be used when the user asks to "add localization", "support multiple languages", "translate error messages", "set up i18n", "translate database content", "configure Accept-Language", "use trans()", "use __()", "set the locale", or when working with translation files, locale detection, or any multi-language feature in a Laravel 12 API.
version: 1.0.0
---

## Two Distinct Concerns

Localization in a Laravel API splits cleanly into two problems. Never mix them.

| Concern              | Stored in                                   | Accessed via                          |
|----------------------|---------------------------------------------|----------------------------------------|
| UI strings, errors   | `lang/{locale}/*.php` or `lang/{locale}.json` | `__('messages.welcome')`             |
| Dynamic DB content   | JSON columns + `spatie/laravel-translatable` | `$product->name` (current locale)    |

Static strings (validation messages, error envelopes, email subjects) belong in `lang/`. Dynamic content created by users or admins (product names, article bodies, CMS pages) belongs in the database. Storing user content in `lang/` files breaks the moment a non-developer needs to edit it. Storing UI strings in the database invites N+1 queries on every request.

---

## Supported Locales as Single Source of Truth

Define the supported locale list in one place and validate every locale string against it before assigning. An unvalidated `App::setLocale($request->input('locale'))` lets a client invent locales the app has no translations for, silently degrading every response.

```php
// config/app.php
'locale'           => 'en',
'fallback_locale'  => 'en',
'locales'          => ['en', 'ar'],
```

Always set `fallback_locale`. When a key is missing in `ar`, Laravel transparently looks it up in `en` instead of returning the raw key.

---

## Locale Detection Middleware

Resolve locale in priority order: authenticated user preference, then explicit `X-Locale` header, then `Accept-Language`, then app default. Validate against `config('app.locales')` at every step.

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.locales', ['en']);
        $fallback  = config('app.fallback_locale', 'en');

        $locale = $this->resolve($request, $supported) ?? $fallback;

        App::setLocale($locale);
        Context::add('locale', $locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function resolve(Request $request, array $supported): ?string
    {
        $user = $request->user();
        if ($user && in_array($user->preferred_locale ?? null, $supported, true)) {
            return $user->preferred_locale;
        }

        $header = $request->header('X-Locale');
        if ($header && in_array($header, $supported, true)) {
            return $header;
        }

        return $request->getPreferredLanguage($supported);
    }
}
```

Register globally in `bootstrap/app.php` (Laravel 11+ — `app/Http/Kernel.php` no longer exists):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(append: [\App\Http\Middleware\SetLocale::class]);
})
```

---

## Translation Files

Two formats coexist. Pick by the shape of the data, not by preference.

```php
// lang/en/messages.php
return [
    'welcome'  => 'Welcome, :name',
    'invoice'  => [
        'paid'     => 'Invoice paid',
        'overdue'  => 'Invoice overdue',
    ],
];

// Access
__('messages.welcome', ['name' => $user->name]);
__('messages.invoice.paid');
```

```json
// lang/ar.json
{
    "Welcome": "أهلاً",
    "Invoice paid": "تم دفع الفاتورة"
}
```

```php
__('Welcome');         // returns "أهلاً" when locale is ar
__('Invoice paid');
```

Use PHP files for nested categorized strings (validation, email templates). Use JSON files for flat naturally-keyed strings — the English text itself is the key.

Publish framework translation files (validation messages, password reset, pagination) before customizing them:

```bash
php artisan lang:publish
```

---

## Validation Messages

Two layers cover every case. Built-in rule messages live in `lang/{locale}/validation.php` after `lang:publish`. Translate the keys for each locale.

For per-field custom messages, use the Form Request `messages()` method with `__()`:

```php
public function messages(): array
{
    return [
        'name.required' => __('users.name_required'),
        'email.unique'  => __('users.email_taken'),
    ];
}
```

Never hard-code English strings in `messages()` — they bypass localization entirely.

---

## Localized API Error Responses

Wire localized exception handling in `bootstrap/app.php` so every error response is translated:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (\App\Exceptions\DomainException $e, $request) {
        return response()->json([
            'message' => __($e->translationKey(), $e->translationParams()),
            'errors'  => [],
        ], $e->getCode() ?: 422);
    });
})
```

Domain exceptions expose a translation key rather than a fixed English message:

```php
final class InsufficientStockException extends DomainException
{
    public function translationKey(): string
    {
        return 'errors.insufficient_stock';
    }
}
```

---

## Database Content with `spatie/laravel-translatable`

The package stores translations as JSON in a single column per attribute. Install with `composer require spatie/laravel-translatable` and migrate the column as `json`.

```php
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'description'];
}
```

```php
$product->name;                                    // current app locale
$product->getTranslation('name', 'en');            // explicit locale
$product->setTranslation('name', 'ar', 'هاتف')->save();
$product->setTranslations('name', ['en' => 'Phone', 'ar' => 'هاتف']);
```

For SEO-friendly multilingual URLs, the companion package `spatie/laravel-sluggable` ships `HasTranslatableSlug` (see `references/database-content.md`). Choose a separate `product_translations` table instead of JSON when datasets are large, locales change frequently, or full-text search per locale matters.

---

## Locale Propagation to Queues, Mail & Notifications

This is the largest hidden bug source. Queue workers run with no request context — `App::getLocale()` inside a job returns the app default, not the dispatching user's locale. Use the `Context` facade (Laravel 11+) to pass locale through the job payload:

```php
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Context;

// In SetLocale middleware (already done above):
Context::add('locale', App::getLocale());

// In every job that emits user-facing output:
public function handle(): void
{
    App::setLocale(Context::get('locale', config('app.fallback_locale')));

    // ...rest of handle()
}
```

For notifications and mailables, pin the locale per-recipient instead of relying on Context. Both APIs accept a `locale()` call:

```php
Notification::locale($user->preferred_locale)->send($user, new InvoicePaid($invoice));

Mail::to($user)->send((new OrderShipped($order))->locale($user->preferred_locale));
```

If the recipient model implements `Illuminate\Contracts\Translation\HasLocalePreference` and returns the locale from `preferredLocale()`, Laravel calls it automatically and the explicit `->locale()` is unnecessary.

---

## Response Headers & RTL Metadata

The `SetLocale` middleware sets `Content-Language` so browsers and proxies handle caching correctly. For frontends that need to flip layout direction without a lookup table, include `dir` in the response envelope:

```php
// config/app.php
'rtl_locales' => ['ar', 'he', 'fa', 'ur'],

// In a base API resource or response macro
'meta' => [
    'locale' => app()->getLocale(),
    'dir'    => in_array(app()->getLocale(), config('app.rtl_locales', []), true) ? 'rtl' : 'ltr',
],
```

---

## Locale-Aware Formatting

Storage is always UTC. Rendering combines locale (language) and timezone (offset) — keep them as separate user columns.

```php
use Illuminate\Support\Number;

now()->locale($locale)->translatedFormat('l, j F Y');   // "الجمعة، 2 مايو 2026"
now()->locale($locale)->diffForHumans();                // "منذ 3 دقائق"

Number::format(1234567.89);                             // locale-aware grouping
Number::currency(99.50, 'USD');                         // "$99.50" / "US$ 99.50"
Number::percentage(12.5);
```

`Number::format()`, `Number::currency()`, `Number::percentage()`, and `Number::fileSize()` all default to the current app locale; pass an explicit `$locale` argument for per-call overrides. `Number::useLocale($locale)` sets a process-wide default.

---

## Plurals

Use `trans_choice()` for any string whose form depends on a count. Arabic has six plural forms, Russian has three, English has two — let the translator pick.

```php
trans_choice('messages.apples', $count, ['count' => $count]);
```

```php
// lang/en/messages.php
'apples' => '{0} no apples|{1} one apple|[2,*] :count apples',
```

The pipe-separated segments map count ranges to forms. `{N}` matches an exact count, `[a,b]` matches an inclusive range, `*` is unbounded.

---

## Testing Localized Features

Set the locale at the top of the test, then assert against the translation key (not the literal string) so tests stay green when copy changes:

```php
use Illuminate\Support\Facades\App;

it('returns Arabic validation message', function () {
    App::setLocale('ar');

    $response = $this->postJson('/api/users', []);

    expect($response->json('errors.name.0'))
        ->toBe(__('users.name_required', [], 'ar'));
});
```

Use `Lang::has('messages.welcome', 'ar')` in a coverage test to fail CI when a new translation key lacks an Arabic counterpart.

---

## Additional Resources

- `references/lang-files.md` — PHP vs JSON file structure, `__()` / `trans()` / `Lang::get()` aliases, replacement parameters, plural syntax deep dive, fallback chains, cache busting, translation-management workflows.
- `references/database-content.md` — `spatie/laravel-translatable` setup, JSON column migrations, querying translatable attributes (`where('name->ar', ...)`), `HasTranslatableSlug`, indexing strategies, and when to use a separate translation table.
- `references/request-flow.md` — Full middleware code, locale validation against the supported list, `Context` propagation to queues, `Notification::locale()` and `Mailable->locale()` pinning, and Pest tests for the middleware.
- `references/formatting.md` — Carbon localized output, `Number` helpers, RTL detection map, currency vs locale separation, and including `dir` in API response envelopes.
