# Translation Files

## Directory Layout

```
lang/
  en/
    messages.php
    validation.php
    auth.php
  ar/
    messages.php
    validation.php
    auth.php
  ar.json
  en.json
```

PHP files live under a per-locale subdirectory. JSON files live at the `lang/` root and are named `<locale>.json`.

Run `php artisan lang:publish` once before customizing framework translations — it copies the default `validation.php`, `auth.php`, `passwords.php`, and `pagination.php` into `lang/en/`.

---

## PHP File Structure

Return a nested array. Keys may be arbitrarily deep; access with dot notation.

```php
// lang/en/messages.php
return [
    'welcome' => 'Welcome, :name',
    'invoice' => [
        'paid'     => 'Invoice :number has been paid',
        'overdue'  => 'Invoice :number is :days days overdue',
        'status'   => [
            'draft'   => 'Draft',
            'sent'    => 'Sent',
            'paid'    => 'Paid',
        ],
    ],
];
```

```php
__('messages.welcome', ['name' => $user->name]);
__('messages.invoice.paid', ['number' => $invoice->number]);
__('messages.invoice.status.paid');
```

---

## JSON File Structure

Flat key/value, where the English text is the key. Best for short user-facing strings without obvious categorisation.

```json
{
    "Welcome": "أهلاً",
    "Sign in": "تسجيل الدخول",
    "Invoice paid": "تم دفع الفاتورة"
}
```

```php
__('Welcome');                    // "أهلاً" when locale is ar
__('Hello, :name', ['name' => $name]);
```

JSON keys may contain placeholders. The English file is implicit — when the active locale is `en`, Laravel returns the key itself.

### When to Pick PHP vs JSON

| Use PHP files when                          | Use JSON files when                       |
|---------------------------------------------|--------------------------------------------|
| Strings group by category (validation.*)    | Strings are short and naturally-keyed     |
| The same key appears in many places         | New strings are added frequently           |
| Translators expect a structured tree        | The English copy itself is meaningful     |
| Plural forms via `trans_choice`             | One-shot UI strings                        |

Mixing both is normal. `__()` searches JSON first, then falls back to PHP files.

---

## Replacement Parameters

Pass a parameter map as the second argument. Placeholders use a leading colon, with optional capitalisation rules baked in.

```php
'greeting' => 'Hello, :name. You have :count messages.',
```

```php
__('messages.greeting', ['name' => 'Sam', 'count' => 3]);
// "Hello, Sam. You have 3 messages."
```

Capitalisation: `:Name` capitalises the first letter, `:NAME` upper-cases the entire value. Place these in the source string, not in PHP code.

---

## Plurals — `trans_choice`

Laravel uses Symfony's plural notation. Each form is separated by `|`. Forms are written `{N} ...` for exact counts and `[a,b] ...` for ranges (`*` is unbounded on either side).

```php
'apples' => '{0} no apples|{1} one apple|[2,*] :count apples',

trans_choice('messages.apples', 0);                    // "no apples"
trans_choice('messages.apples', 1);                    // "one apple"
trans_choice('messages.apples', 7, ['count' => 7]);    // "7 apples"
```

Some locales (Arabic, Russian, Polish) require more forms. Provide all of them in the matching translation file:

```php
// lang/ar/messages.php — Arabic has six forms
'apples' => '{0} لا تفاح|{1} تفاحة واحدة|{2} تفاحتان|[3,10] :count تفاحات|[11,99] :count تفاحة|[100,*] :count تفاحة',
```

---

## Fallback Chains

`config('app.fallback_locale')` is the safety net. Resolution order for `__('messages.welcome')`:

1. Active locale's PHP/JSON file.
2. Fallback locale's PHP/JSON file.
3. The raw key (`messages.welcome`) — a clear visual signal that a translation is missing.

Always set `fallback_locale` to a locale you maintain fully. A missing fallback file produces raw keys in the response — visible to end users.

---

## Caching & Cache Busting

Translation files are read on demand and cached internally per request. After pulling new translations from version control or running `lang:publish`, clear the application caches that may have captured stale view output:

```bash
php artisan view:clear
php artisan cache:clear
php artisan optimize:clear
```

`php artisan optimize` does not cache translation files themselves; it caches config and routes. Translation files are cheap to load and need no separate cache.

---

## Translation Management

Two approaches dominate. Choose one and stick to it.

1. **Files in git** — translators send PRs or use a separate Git client. Simple, free, version-controlled. Works well for small teams and a stable string catalogue.
2. **Translation platform** (Lokalise, POEditor, Crowdin, Phrase) — translators work in a web UI; CI syncs files into `lang/`. Required when non-developer translators are involved or when the catalogue churns weekly.

Never let translators edit production files directly over SFTP. Always go through a review checkpoint.

---

## `__()` vs `trans()` vs `Lang::get()`

All three call the same underlying translator. Pick one and use it everywhere.

```php
__('messages.welcome');                                   // shortest
trans('messages.welcome');                                // explicit
\Illuminate\Support\Facades\Lang::get('messages.welcome'); // OO
```

`__()` wins on terseness inside Blade and JSON arrays. Use `Lang::get()` only when you need its less-common siblings: `Lang::has()`, `Lang::choice()`, `Lang::getLocale()`.

---

## Coverage Tests

Use `Lang::has()` in a CI test to fail when a new English key has no counterpart in another locale.

```php
use Illuminate\Support\Facades\Lang;

it('has Arabic translations for every English message key', function () {
    $en = require lang_path('en/messages.php');

    foreach (array_keys(\Illuminate\Support\Arr::dot($en)) as $key) {
        expect(Lang::has("messages.{$key}", 'ar'))
            ->toBeTrue("Missing Arabic translation for messages.{$key}");
    }
});
```

This catches forgotten translations before they reach production as raw fallback strings.
