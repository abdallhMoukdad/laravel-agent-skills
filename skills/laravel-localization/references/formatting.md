# Locale-Aware Formatting

Locale governs *how* values are rendered: which words, which digit grouping, which date order. Timezone governs *which moment* a timestamp refers to. They are independent. Always store UTC in the database, then render in the user's locale and timezone at output time.

---

## Carbon Date and Time

```php
$now = now();                          // UTC, app default locale

// Locale only — same instant, different language
$now->locale('ar')->translatedFormat('l, j F Y');     // "الجمعة، 2 مايو 2026"
$now->locale('en')->translatedFormat('l, j F Y');     // "Friday, 2 May 2026"

// Timezone only — different instant, same language
$now->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'); // "2026-05-02 13:30"

// Both
$now->setTimezone($user->timezone)
    ->locale($user->preferred_locale)
    ->translatedFormat('l, j F Y g:i A');
```

`translatedFormat()` honours the locale set via `->locale()`. Plain `format()` does not — it always returns English month/day names regardless of locale.

### Relative Times

```php
$invoice->created_at->locale($user->preferred_locale)->diffForHumans();
// "منذ 3 أيام" / "3 days ago"
```

`diffForHumans()` is fully localised by Carbon's bundled translations.

---

## Number Formatting

The `Illuminate\Support\Number` facade-style helper (Laravel 11+) wraps PHP's `intl` extension. Every method accepts an explicit `$locale` argument; pass none to use the current app locale.

```php
use Illuminate\Support\Number;

Number::format(1234567.89);                    // "1,234,567.89" (en) / "1٬234٬567٫89" (ar)
Number::format(1234.5, locale: 'de');          // "1.234,5"
Number::format(1234.5, precision: 2);          // "1,234.50"

Number::currency(99.50, 'USD');                // "$99.50" (en) / "US$ 99.50" (ar)
Number::currency(99.50, 'EUR', 'fr');          // "99,50 €"

Number::percentage(12.5);                      // "13%" (default precision 0)
Number::percentage(12.5, precision: 2);        // "12.50%"

Number::fileSize(1500);                        // "1.46 KB"
Number::fileSize(1_500_000_000);               // "1.40 GB"

Number::ordinal(3);                            // "3rd"
Number::spell(3, locale: 'ar');                // "ثلاثة"
Number::abbreviate(1_234_567);                 // "1M"
```

### `Number::useLocale()`

For most apps, the `SetLocale` middleware should also set the Number locale once per request. Reach for this only if a process needs a fixed locale regardless of `App::getLocale()`.

```php
// Inside middleware, after App::setLocale($locale):
Number::useLocale($locale);
```

---

## RTL Detection

Right-to-left layouts are not derivable from the locale string itself — `'ar'` is RTL but `'arn'` (Mapudungun) is LTR. Maintain an explicit map.

```php
// config/app.php
'rtl_locales' => ['ar', 'he', 'fa', 'ur'],
```

```php
// app/Support/Direction.php
namespace App\Support;

final class Direction
{
    public static function for(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        return in_array($locale, config('app.rtl_locales', []), true)
            ? 'rtl'
            : 'ltr';
    }
}
```

```php
Direction::for();          // current locale's direction
Direction::for('ar');      // explicit
```

---

## Currency vs Locale — Two Different Columns

A user in Egypt browsing an English UI still wants prices in EGP, not USD. Locale controls the words and digit grouping; currency controls the value itself. Store them separately on the user model.

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('preferred_locale', 8)->default('en');
    $table->string('preferred_currency', 3)->default('USD');
    $table->string('timezone', 64)->default('UTC');
});
```

Render with both:

```php
Number::currency(
    $invoice->total,
    in: $user->preferred_currency,
    locale: $user->preferred_locale,
);
```

Currency conversion (the *value* changing because EGP differs from USD) is a separate concern from formatting. Convert before formatting.

---

## Including `dir` in API Response Envelopes

Frontends rendering Arabic content alongside English chrome need to flip layout direction without shipping their own locale-direction map. Include it in the response envelope.

```php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BaseResource extends JsonResource
{
    public function with($request): array
    {
        return [
            'meta' => [
                'locale' => app()->getLocale(),
                'dir'    => \App\Support\Direction::for(),
            ],
        ];
    }
}
```

For non-resource JSON responses, set the same fields via a response macro registered in `AppServiceProvider`:

```php
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;

Response::macro('localized', function (array $data, int $status = 200) {
    return new JsonResponse([
        ...$data,
        'meta' => [
            'locale' => app()->getLocale(),
            'dir'    => \App\Support\Direction::for(),
        ],
    ], $status);
});
```

```php
return response()->localized(['products' => $products]);
```

---

## Pitfalls

**`format()` ignores locale.** `now()->format('l')` always returns "Friday", never the Arabic translation. Use `translatedFormat()`.

**`Number` requires the `intl` PHP extension.** Without it, methods throw `RuntimeException`. Verify with `php -m | grep intl` on production servers.

**Carbon parses relative input in its own locale, not the app locale.** Always pass `Carbon::parse($input, $tz)` with explicit ISO 8601 strings from the API, not natural language.

**Timezone changes alter the *date*, not just the *time*.** A user in `Pacific/Auckland` sees a UTC midnight as the next day. Format with timezone first, then humanise.
