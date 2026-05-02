<?php

/**
 * Static verifier for PHP symbols referenced in skill markdown.
 *
 * Catches the dominant failure mode of these skills: fabricated identifiers
 * (class names, attributes, methods, packages) that "feel right" but don't
 * exist in Laravel 12.
 *
 * Checks:
 *   1. Every `use Foo\Bar;` import resolves via Composer autoload.
 *   2. Every `Facade::method(...)` call exists on the facade's underlying
 *      class (per a hand-curated facade map).
 *   3. Hard-coded gotchas list (methods we know are commonly hallucinated).
 *
 * Requires: laravel/framework installed at one of:
 *   - /home/abdallh/vendor/laravel/framework
 *   - ../vendor/laravel/framework (relative to project)
 *
 * Run: php scripts/verify-symbols.php
 *
 * Exits 0 if every symbol resolves, 1 otherwise.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// Find a working autoloader (the plugin repo itself has none).
$autoloadCandidates = array_filter([
    getenv('VENDOR_PATH') ?: null,
    "{$root}/.verify/vendor/autoload.php",
    '/home/abdallh/vendor/autoload.php',
    "{$root}/../vendor/autoload.php",
    "{$root}/vendor/autoload.php",
]);
$autoloader = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoloader = $candidate;
        break;
    }
}
if (!$autoloader) {
    fwrite(STDERR, "ERROR: no Composer autoloader found. Tried:\n  " . implode("\n  ", $autoloadCandidates) . "\n");
    fwrite(STDERR, "Install laravel/framework somewhere reachable, or run from inside a Laravel app's vendor.\n");
    exit(1);
}
require_once $autoloader;

// Locate every .md file under skills/
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/skills"));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'md') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$errors = [];
$warnings = [];
$counters = ['imports' => 0, 'facades' => 0, 'gotchas' => 0];

// === 1. Import existence ===
$skipPrefixes = ['App\\', 'Database\\', 'Tests\\', 'Modules\\', 'Pest\\'];
// Namespace prefixes for third-party packages we don't vendor locally.
// Symbols under these prefixes are accepted without verification.
// Add a new entry only when (a) the package is on Packagist and (b) the
// namespace it ships matches the prefix exactly.
$thirdPartyPrefixes = [
    'Laravel\\Sanctum\\',
    'Laravel\\Passport\\',
    'Laravel\\Horizon\\',
    'Laravel\\Telescope\\',
    'Laravel\\Cashier\\',
    'Spatie\\LaravelData\\',
    'Spatie\\Permission\\',
    'Spatie\\Health\\',
    'Lorisleiva\\Actions\\',
    'TiMacDonald\\Log\\',
    'Stripe\\',
    'Symfony\\',
    'Carbon\\',
    'Mockery\\',
    'PHPUnit\\',
    'Psr\\',
    'GuzzleHttp\\',
    'Faker\\',
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    preg_match_all('/^\s*use\s+([\w\\\\]+);/m', $content, $matches);
    foreach ($matches[1] as $sym) {
        $sym = ltrim($sym, '\\');
        // Skip class-body trait imports (`    use SoftDeletes;`) — these
        // re-reference a class already imported by FQCN at the top of the
        // file. Only verify true namespace imports (those containing `\`).
        if (!str_contains($sym, '\\')) continue;
        // Skip user-namespace and Pest helpers
        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($sym, $prefix)) continue 2;
        }
        // Skip third-party packages we don't vendor
        foreach ($thirdPartyPrefixes as $prefix) {
            if (str_starts_with($sym, $prefix)) continue 2;
        }
        // Must look like a class
        if (!preg_match('/^[A-Z][\w\\\\]*$/', $sym)) continue;

        $counters['imports']++;
        if (!class_exists($sym) && !interface_exists($sym) && !trait_exists($sym) && !enum_exists($sym)) {
            $errors[] = ['MISSING IMPORT', $sym, relpath($file, $root)];
        }
    }
}

// === 2. Facade method existence ===
// Each facade maps to ALL classes whose methods are reachable through it
// (manager + underlying contract/instance + testing fake, if any).
$facadeMap = [
    'Cache' => [
        \Illuminate\Cache\Repository::class,
        \Illuminate\Contracts\Cache\Store::class,
        \Illuminate\Contracts\Cache\LockProvider::class,
    ],
    'DB' => [
        \Illuminate\Database\DatabaseManager::class,
        \Illuminate\Database\Connection::class,
    ],
    'Log' => [
        \Illuminate\Log\LogManager::class,
        \Illuminate\Log\Logger::class,
        \Psr\Log\LoggerInterface::class,
    ],
    'Schema' => [\Illuminate\Database\Schema\Builder::class],
    'Mail' => [
        \Illuminate\Mail\MailManager::class,
        \Illuminate\Mail\Mailer::class,
        \Illuminate\Contracts\Mail\Mailer::class,
        \Illuminate\Support\Testing\Fakes\MailFake::class,
    ],
    'Event' => [
        \Illuminate\Events\Dispatcher::class,
        \Illuminate\Support\Testing\Fakes\EventFake::class,
    ],
    'Queue' => [
        \Illuminate\Queue\QueueManager::class,
        \Illuminate\Contracts\Queue\Queue::class,
        \Illuminate\Support\Testing\Fakes\QueueFake::class,
    ],
    'Bus' => [
        \Illuminate\Bus\Dispatcher::class,
        \Illuminate\Contracts\Bus\Dispatcher::class,
        \Illuminate\Support\Testing\Fakes\BusFake::class,
    ],
    'Notification' => [
        \Illuminate\Notifications\ChannelManager::class,
        \Illuminate\Contracts\Notifications\Dispatcher::class,
        \Illuminate\Support\Testing\Fakes\NotificationFake::class,
    ],
    'Storage' => [
        \Illuminate\Filesystem\FilesystemManager::class,
        \Illuminate\Contracts\Filesystem\Filesystem::class,
        \Illuminate\Filesystem\FilesystemAdapter::class,
    ],
    'Hash' => [
        \Illuminate\Hashing\HashManager::class,
        \Illuminate\Contracts\Hashing\Hasher::class,
    ],
    'Validator' => [\Illuminate\Validation\Factory::class],
    'Auth' => [
        \Illuminate\Auth\AuthManager::class,
        \Illuminate\Contracts\Auth\Guard::class,
        \Illuminate\Contracts\Auth\StatefulGuard::class,
    ],
    'Gate' => [
        \Illuminate\Auth\Access\Gate::class,
        \Illuminate\Contracts\Auth\Access\Gate::class,
    ],
    'Route' => [
        \Illuminate\Routing\Router::class,
        \Illuminate\Routing\RouteRegistrar::class,
    ],
    'Session' => [
        \Illuminate\Session\SessionManager::class,
        \Illuminate\Contracts\Session\Session::class,
    ],
    'Redis' => [\Illuminate\Redis\RedisManager::class],
    'Config' => [\Illuminate\Config\Repository::class],
    'Crypt' => [\Illuminate\Encryption\Encrypter::class],
    'Artisan' => [\Illuminate\Contracts\Console\Kernel::class],
    'File' => [\Illuminate\Filesystem\Filesystem::class],
    'Process' => [
        \Illuminate\Process\Factory::class,
        \Illuminate\Process\PendingProcess::class,
    ],
    'Str' => [\Illuminate\Support\Str::class],
    'Arr' => [\Illuminate\Support\Arr::class],
    'Schedule' => [\Illuminate\Console\Scheduling\Schedule::class],
    'RateLimiter' => [\Illuminate\Cache\RateLimiter::class],
    'Context' => [\Illuminate\Log\Context\Repository::class],
    'Http' => [
        \Illuminate\Http\Client\Factory::class,
        \Illuminate\Http\Client\PendingRequest::class,
    ],
];

// Methods inherited from Facade base or Mockery — skip these.
$facadeBaseMethods = [
    'spy', 'partialMock', 'shouldReceive', 'expects',
    'shouldHaveReceived', 'shouldNotHaveReceived', 'swap',
    'getFacadeRoot', 'getFacadeAccessor', 'isMock',
    'getMockBuilder', 'resolved', 'macro', 'hasMacro',
    'flushMacros', 'mixin', 'when',
];

// Per-facade magic methods (handled via __call / allowedAttributes / etc.)
// that won't show up via Reflection but are valid at runtime.
$facadeMagicMethods = [
    'Route' => [
        // RouteRegistrar::$allowedAttributes
        'as', 'controller', 'domain', 'middleware', 'name',
        'namespace', 'prefix', 'scopeBindings', 'where',
        'withoutMiddleware',
    ],
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if (preg_match_all('/\b([A-Z][a-zA-Z]+)::([a-zA-Z_]+)\s*\(/', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as [$_, $facade, $method]) {
            if (!isset($facadeMap[$facade])) continue;
            if (in_array($method, $facadeBaseMethods, true)) continue;
            if (isset($facadeMagicMethods[$facade]) && in_array($method, $facadeMagicMethods[$facade], true)) continue;

            $counters['facades']++;
            $targets = $facadeMap[$facade];

            // Method exists on at least one target? Pass.
            $found = false;
            $missingTargets = [];
            foreach ($targets as $target) {
                if (!class_exists($target) && !interface_exists($target)) {
                    $missingTargets[] = $target;
                    continue;
                }
                try {
                    $rc = new ReflectionClass($target);
                    if ($rc->hasMethod($method)) { $found = true; break; }
                } catch (\Throwable $e) {
                    // try next
                }
            }
            if ($found) continue;

            // Also allow methods declared on the Facade subclass itself
            // (e.g., static helpers like Cache::driver()).
            $facadeClass = "Illuminate\\Support\\Facades\\{$facade}";
            if (class_exists($facadeClass) && (new ReflectionClass($facadeClass))->hasMethod($method)) {
                continue;
            }

            $where = relpath($file, $root);
            if ($missingTargets && count($missingTargets) === count($targets)) {
                $errors[] = ['FACADE TARGETS MISSING', "{$facade}: " . implode(', ', $missingTargets), $where];
            } else {
                $errors[] = ['MISSING METHOD', "{$facade}::{$method}()", $where];
            }
        }
    }
}

// === 3. Hard-coded gotcha list (methods we *know* don't exist) ===
$gotchas = [
    // pattern => human-readable explanation
    '/\bCache::fake\s*\(/' => "Cache::fake() does NOT exist in Laravel 12 (use Cache::spy() or array driver in tests)",
    '/\bLog::fake\s*\(/' => "Log::fake() does NOT exist in Laravel 12 core (use Event::fake([MessageLogged::class]) or timacdonald/log-fake)",
    '/Cache::putForever\b/' => "putForever() doesn't exist; use Cache::forever()",
    '/ShouldBeDispatchedAfterCommit/' => "ShouldBeDispatchedAfterCommit is fabricated; use ShouldQueueAfterCommit or \$afterCommit = true",
    '/#\[\s*AsListener\b/' => "#[AsListener] attribute doesn't exist in Laravel; use auto-discovery or Event::listen()",
    '/#\[\s*ListensTo\b/' => "#[ListensTo] attribute doesn't exist in Laravel; use auto-discovery or Event::listen()",
    '/->makeMany\s*\(/' => "makeMany() doesn't exist on Eloquent factories; use ->count(N)->make() or createMany()",
    '/spatie\/laravel-log-fake/' => "package spatie/laravel-log-fake does not exist on Packagist; use timacdonald/log-fake",
    '/Spatie\\\\LaravelLogFake\\\\FakeLogger/' => "Spatie\\LaravelLogFake\\FakeLogger does not exist; use TiMacDonald\\Log\\LogFake",
];

foreach ($files as $file) {
    $content = file_get_contents($file);
    foreach ($gotchas as $pattern => $msg) {
        if (preg_match($pattern, $content)) {
            $counters['gotchas']++;
            $errors[] = ['GOTCHA', $msg, relpath($file, $root)];
        }
    }
}

// === Output ===
echo "Checked {$counters['imports']} imports, {$counters['facades']} facade calls, "
    . "and {$counters['gotchas']} gotcha matches across " . count($files) . " files.\n\n";

if ($warnings) {
    echo "Warnings (" . count($warnings) . "):\n";
    foreach ($warnings as [$kind, $what, $where]) {
        echo "  ⚠ [{$kind}] {$what}\n      in {$where}\n";
    }
    echo "\n";
}

if (empty($errors)) {
    echo "OK — no fabricated symbols detected.\n";
    exit(0);
}

echo "Problems (" . count($errors) . "):\n";
foreach ($errors as [$kind, $what, $where]) {
    echo "  ✗ [{$kind}] {$what}\n      in {$where}\n";
}
echo "\nFAIL\n";
exit(1);

function relpath(string $abs, string $root): string {
    return str_replace($root . '/', '', $abs);
}
