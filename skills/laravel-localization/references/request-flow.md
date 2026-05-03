# Request Flow & Locale Propagation

The hardest bugs in a localized API live outside the request — in queue workers, scheduled commands, and notifications dispatched after the response is sent. The middleware sets the locale for one request; the rest of the system must inherit it explicitly.

---

## The Full SetLocale Middleware

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
        // 1. Authenticated user preference
        $user = $request->user();
        if ($user && in_array($user->preferred_locale ?? null, $supported, true)) {
            return $user->preferred_locale;
        }

        // 2. Explicit X-Locale header
        $header = $request->header('X-Locale');
        if ($header && in_array($header, $supported, true)) {
            return $header;
        }

        // 3. Accept-Language negotiation
        return $request->getPreferredLanguage($supported);
    }
}
```

### Why This Order

1. **Authenticated user first** — the user explicitly chose a locale once, and that preference outlives any individual request. Honour it even if their browser sends a different `Accept-Language`.
2. **`X-Locale` header second** — frontends often need to override the user's stored preference (a "preview in Arabic" toggle). An explicit per-request signal beats the persisted setting.
3. **`Accept-Language` third** — browser-supplied default, sane for anonymous users.
4. **Fallback last** — never let an unrecognised locale leak through.

`$request->getPreferredLanguage($supported)` parses the `Accept-Language` header and returns the highest-priority entry that matches the supported list. It returns the first supported locale if no match exists — which is fine because the supported list begins with the app default.

### Validating Against `config('app.locales')`

Every step checks `in_array($candidate, $supported, true)`. Without this guard, a client can send `X-Locale: xx-fake` and `App::setLocale('xx-fake')` succeeds — Laravel happily sets a locale it has no translations for, returning raw fallback strings for the rest of the request.

---

## Registration in `bootstrap/app.php`

Laravel 11+ removed `app/Http/Kernel.php`. Register middleware via the `withMiddleware` callback:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // exception handlers
    })
    ->create();
```

For a global apply on every route, use `$middleware->append(SetLocale::class)` instead of scoping to `api`.

---

## Propagating Locale to Queues

Queue workers run in a separate PHP process with no request, no `Accept-Language`, no authenticated user. `App::getLocale()` inside a job returns the worker's app default unless something passed the locale through.

The `Context` facade (Laravel 11+, in `Illuminate\Support\Facades\Context`) automatically includes added values in every dispatched job's payload.

```php
// Set once in the SetLocale middleware (already shown):
Context::add('locale', App::getLocale());

// Read once at the top of every job's handle():
public function handle(): void
{
    App::setLocale(Context::get('locale', config('app.fallback_locale')));

    // The rest of handle() now uses the dispatching user's locale.
}
```

For mailables and notifications dispatched from queued jobs, the same Context value covers them — but the explicit `->locale()` call below is more reliable when the recipient differs from the dispatcher.

---

## Notifications

Notifications expose `Notification::locale()` to pin the locale for a single send call. This is independent of `App::getLocale()` — useful when sending to multiple recipients with different preferences.

```php
use Illuminate\Support\Facades\Notification;

Notification::locale($user->preferred_locale)
    ->send($user, new InvoicePaid($invoice));
```

A cleaner option: implement `Illuminate\Contracts\Translation\HasLocalePreference` on the recipient model.

```php
use Illuminate\Contracts\Translation\HasLocalePreference;

class User extends Authenticatable implements HasLocalePreference
{
    public function preferredLocale(): ?string
    {
        return $this->preferred_locale;
    }
}
```

Now `$user->notify(new InvoicePaid($invoice))` automatically picks up the user's locale — no `Notification::locale()` call needed.

---

## Mailables

`Mailable->locale()` works the same way as the notification version:

```php
Mail::to($user)->send(
    (new OrderShipped($order))->locale($user->preferred_locale)
);
```

If the recipient implements `HasLocalePreference`, Laravel calls `preferredLocale()` automatically.

---

## Conditional Locale Logic

`app()->isLocale()` is the canonical guard for code paths that differ per locale:

```php
if (app()->isLocale('ar')) {
    return view('pdf.rtl', $data);
}
```

Avoid string comparisons against `app()->getLocale()` directly — `isLocale()` is what the framework itself uses internally.

---

## Testing the Middleware

```php
use Illuminate\Support\Facades\App;

it('uses authenticated user preferred locale first', function () {
    $user = User::factory()->create(['preferred_locale' => 'ar']);

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'en')
        ->getJson('/api/me');

    expect(App::getLocale())->toBe('ar');
});

it('falls back to Accept-Language for guests', function () {
    $this->withHeader('Accept-Language', 'ar,en;q=0.9')
        ->getJson('/api/products');

    expect(App::getLocale())->toBe('ar');
});

it('rejects unsupported X-Locale and uses fallback', function () {
    $this->withHeader('X-Locale', 'xx-fake')
        ->getJson('/api/products');

    expect(App::getLocale())->toBe(config('app.fallback_locale'));
});

it('sets Content-Language response header', function () {
    $response = $this->withHeader('X-Locale', 'ar')->getJson('/api/products');

    expect($response->headers->get('Content-Language'))->toBe('ar');
});
```

---

## Common Pitfall — Order of Operations

The user model's `preferred_locale` change must happen *before* `App::setLocale()`. A common mistake:

```php
// Wrong — setLocale was already called in middleware with the OLD value
public function update(Request $request)
{
    $request->user()->update(['preferred_locale' => $request->input('locale')]);
    return response()->json(['message' => __('users.preferences_saved')]);
    //                                       ^^ this uses the OLD locale
}
```

Fix by re-setting the locale after the update:

```php
public function update(Request $request)
{
    $user = $request->user();
    $user->update(['preferred_locale' => $request->input('locale')]);

    App::setLocale($user->preferred_locale);

    return response()->json(['message' => __('users.preferences_saved')]);
}
```

Or return a translation key and let the client render the message — also avoids the double-locale problem.
