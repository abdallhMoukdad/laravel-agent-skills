## Log Channel Drivers

Laravel wraps Monolog. Each entry in `config/logging.php` under `channels` is a named channel with a `driver` key.

### `single`

Writes all log entries to a single file. Never use in production — the file grows without bound.

```php
'single' => [
    'driver' => 'single',
    'path'   => storage_path('logs/laravel.log'),
    'level'  => env('LOG_LEVEL', 'debug'),
    'replace_placeholders' => true,
],
```

### `daily`

Writes to a new file each day, named `laravel-YYYY-MM-DD.log`. Retains `days` most recent files, deletes older ones. Use this for any file-based channel.

```php
'daily' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/laravel.log'),
    'level'  => env('LOG_LEVEL', 'debug'),
    'days'   => 14,
    'replace_placeholders' => true,
],
```

### `slack`

Posts log entries to a Slack channel via an incoming webhook URL. Set `level` to `critical` or higher — never send `debug` or `info` noise to Slack.

```php
'slack' => [
    'driver'   => 'slack',
    'url'      => env('LOG_SLACK_WEBHOOK_URL'),
    'username' => 'Laravel Log',
    'emoji'    => ':boom:',
    'level'    => env('LOG_SLACK_LEVEL', 'critical'),
],
```

### `stderr`

Writes to `php://stderr`. The platform (Docker, Kubernetes, Heroku, AWS ECS) captures it. Use in all containerized and cloud deployments instead of writing to files.

```php
'stderr' => [
    'driver'    => 'monolog',
    'handler'   => Monolog\Handler\StreamHandler::class,
    'formatter' => Monolog\Formatter\JsonFormatter::class,
    'with'      => ['stream' => 'php://stderr'],
    'level'     => env('LOG_LEVEL', 'debug'),
],
```

Set `formatter` to `JsonFormatter` when the platform expects structured JSON (Datadog, Papertrail, CloudWatch). Omit it for human-readable line output.

### `syslog`

Writes to the system syslog. Useful when the host OS aggregates logs via rsyslog or syslog-ng.

```php
'syslog' => [
    'driver'   => 'syslog',
    'facility' => LOG_USER,
    'level'    => env('LOG_LEVEL', 'debug'),
    'formatter' => Monolog\Formatter\LineFormatter::class,
],
```

### `errorlog`

Writes via PHP's `error_log()`. Ends up wherever PHP is configured to send errors (varies by server configuration). Useful as a fallback when no other channel is configured.

```php
'errorlog' => [
    'driver' => 'errorlog',
    'level'  => 'debug',
],
```

### `stack`

Aggregates multiple channels into one. Writing to the `stack` channel fans out to all listed `channels`. Use as the default in production so entries are simultaneously written to a file and sent to an alerting channel.

```php
'stack' => [
    'driver'            => 'stack',
    'channels'          => ['daily', 'slack'],
    'ignore_exceptions' => false,
],
```

`ignore_exceptions` controls whether a failure in one downstream channel (e.g., Slack webhook is down) suppresses exceptions. Default `false` is safer — set to `true` only when a non-critical channel should not cause request failures.

### `null`

Discards all log entries. Use in tests where a Log fake is not available, or to silence a noisy third-party package.

```php
'null' => [
    'driver' => 'monolog',
    'handler' => Monolog\Handler\NullHandler::class,
],
```

---

## The `tap` Key — Customizing Formatters and Processors

Use `tap` to attach a custom class that modifies the underlying Monolog handler after it is constructed. The class receives the channel instance and can call `pushProcessor()` or `setFormatter()` on the handler.

```php
'daily' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/laravel.log'),
    'level'  => 'debug',
    'tap'    => [App\Logging\AddAppVersionProcessor::class],
],
```

```php
// app/Logging/AddAppVersionProcessor.php
namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class AddAppVersionProcessor
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            // Monolog 3 (Laravel 12): LogRecord is immutable — use ->with() to return a modified copy
            $handler->pushProcessor(function (LogRecord $record): LogRecord {
                return $record->with(extra: array_merge($record->extra, [
                    'app_version' => config('app.version'),
                ]));
            });
        }
    }
}
```

---

## Log Stacks with Channel Arrays

A `stack` channel can reference any other named channels, including other stacks. Avoid circular references.

```php
'channels' => [
    'production' => [
        'driver'   => 'stack',
        'channels' => ['stderr', 'slack'],
    ],
    'local' => [
        'driver'   => 'stack',
        'channels' => ['daily'],
    ],
],
```

Configure per-environment with `LOG_CHANNEL`:

```dotenv
# Production container
LOG_CHANNEL=production
LOG_LEVEL=info

# Local development
LOG_CHANNEL=local
LOG_LEVEL=debug
```

---

## Environment-Specific Channel Switching

The `LOG_CHANNEL` env variable controls which channel `Log::info(...)` (and the default logger) writes to. The default value is defined in `config/logging.php`:

```php
'default' => env('LOG_CHANNEL', 'stack'),
```

Set `LOG_CHANNEL` in `.env` files or in your deployment platform's environment configuration. Never hardcode a channel name in application code — always read from config.

---

## Per-Channel Level Filtering

Each channel filters by `level`. Entries below the configured level are discarded by that channel. A `stack` channel does not have its own level; filtering happens at the individual channel level.

Example: send everything to the file, only `critical` and above to Slack:

```php
'stack' => [
    'driver'   => 'stack',
    'channels' => ['daily', 'slack'],
],
'daily' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/laravel.log'),
    'level'  => 'debug', // captures everything
],
'slack' => [
    'driver' => 'slack',
    'url'    => env('LOG_SLACK_WEBHOOK_URL'),
    'level'  => 'critical', // only critical and emergency
],
```

---

## On-Demand Custom Channels

Create a channel at runtime without defining it in `config/logging.php`:

```php
use Illuminate\Support\Facades\Log;

$logger = Log::build([
    'driver' => 'single',
    'path'   => storage_path('logs/import-' . now()->format('Y-m-d') . '.log'),
]);

Log::stack([$logger, 'daily'])->info('import.started', ['file' => $filename]);
```

---

## Full Production `config/logging.php` Example

```php
<?php

use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace'   => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [
        'stack' => [
            'driver'            => 'stack',
            'channels'          => ['stderr', 'slack'],
            'ignore_exceptions' => false,
        ],

        'stderr' => [
            'driver'    => 'monolog',
            'handler'   => StreamHandler::class,
            'formatter' => JsonFormatter::class,
            'with'      => ['stream' => 'php://stderr'],
            'level'     => env('LOG_LEVEL', 'debug'),
        ],

        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'days'   => 14,
        ],

        'slack' => [
            'driver'   => 'slack',
            'url'      => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel',
            'emoji'    => ':rotating_light:',
            'level'    => 'critical',
        ],

        'audit' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/audit.log'),
            'level'  => 'info',
            'days'   => 90,
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],
    ],
];
```
