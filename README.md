# tibor-src/a2a-laravel

Laravel package for the [Agent2Agent (A2A) protocol](https://a2a-protocol.org/latest/specification/), built on [`tibor-src/a2a-php`](https://github.com/tibor-src/a2a-php) and the [Laravel AI SDK](https://laravel.com/docs/ai-sdk). Targets specification **v1.0.1** (protocol version `1.0`).

The package follows [nunomaduro/skeleton-php](https://github.com/nunomaduro/skeleton-php) conventions: `declare(strict_types=1)`, `final` classes, Pest (Orchestra Testbench), Larastan at max, Pint, Rector, and 100% type coverage.

## Requirements

- PHP 8.5+
- Laravel 12 or 13 (the versions [`laravel/ai`](https://github.com/laravel/ai) supports)
- [`tibor-src/a2a-php`](https://github.com/tibor-src/a2a-php) ^0.1.0

## Installation

```bash
composer require tibor-src/a2a-laravel
```

Until both packages are on Packagist, add the GitHub repositories:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/tibor-src/a2a-php.git" },
        { "type": "vcs", "url": "https://github.com/tibor-src/a2a-laravel.git" }
    ]
}
```

Publish config and migrations:

```bash
php artisan vendor:publish --tag=a2a-config
php artisan vendor:publish --tag=a2a-migrations
php artisan migrate
```

## Serve an agent

```php
use Illuminate\Support\Facades\Route;

Route::a2a(); // JSON-RPC, HTTP+JSON, SSE, and /.well-known/agent-card.json
```

Mount under a prefix if the rest of the app owns `/`:

```php
Route::a2a('a2a');
```

Point `a2a.agent` at a Laravel AI agent to serve it over A2A (card name, instructions, and tools-as-skills are taken from the class):

```php
// config/a2a.php
'agent' => App\Ai\SupportAgent::class,
```

Or implement `TiborSrc\A2A\Server\AgentExecutor` and set `a2a.executor`. Generate a stub:

```bash
php artisan make:a2a-executor SupportExecutor
```

## Call a remote agent as a Laravel AI tool

```php
use TiborSrc\A2A\Laravel\Facades\A2A;

// config/a2a.php
'agents' => [
    'research' => ['card' => 'https://research.example.com'],
],

public function tools(): iterable
{
    return [
        A2A::tool('research'),
    ];
}
```

Fetch and cache cards:

```bash
php artisan a2a:cards
php artisan a2a:cards:fetch
php artisan a2a:cards:fetch research
```

`A2A::client($url)` returns the core SDK client. Conversation `contextId` values are passed through to Laravel AI `RemembersConversations::continue()` when the served agent implements that interface.

## Queues, storage, auth

| Config | Default | Purpose |
| --- | --- | --- |
| `a2a.store` | `memory` | `memory`, `cache`, `file`, or `eloquent` |
| `a2a.queue.enabled` | `false` | Dispatch execution to the queue (Horizon-friendly) |
| `a2a.auth.enabled` | `false` | Require the configured guard (Sanctum by default, Passport via `a2a.auth.guard`) |
| `a2a.auth.abilities` | `[]` | Map skill ids to token abilities (`tokenCan`) |

```bash
php artisan a2a:prune --days=14
```

Push notification configs are stored with the task and delivered by a queued job (`tries = 3`, backoff 1/5/15s). Listen for `TaskCreated`, `TaskUpdated`, `TaskCompleted`, `TaskCanceled`, `PushNotificationQueued`, `PushNotificationSent`, and `PushNotificationFailed` (Pulse/Nightwatch-friendly; no hard dependency).

## Testing

```php
use TiborSrc\A2A\Laravel\Facades\A2A;

A2A::fake();
A2A::fake()->respondWithText('https://agent.example', 'pong');

A2A::client('https://agent.example')->sendMessage(...);

A2A::fake()->assertSent('https://agent.example');
A2A::fake()->assertSentCount(1);
A2A::fake()->assertNothingSent();
```

## Conformance

Official [a2a-tck](https://github.com/a2aproject/a2a-tck) against the Orchestra Testbench workbench, spec media type **1.0.1**, transports `jsonrpc,http_json`. Workbench uses `TiborSrc\A2A\Server\TckExecutor` from `a2a-php`.

| Mode | Binding | MUST | SHOULD |
| --- | --- | --- | --- |
| Sync | JSON-RPC + HTTP+JSON | 81 passed, 0 failed, 11 skipped | 9 passed, 0 failed, 1 skipped |
| Queued | JSON-RPC + HTTP+JSON | 81 passed, 0 failed, 11 skipped | 9 passed, 0 failed, 1 skipped |

```bash
composer test:tck
A2A_QUEUE=1 composer test:tck
php artisan a2a:tck --dry-run
```

gRPC is not implemented (same as `a2a-php` v0.1.0).

## Development

```bash
composer install
composer test
composer test:tck
```

## Follow-ups

- Optional `laravel/mcp` bridge so the same agent is also an MCP server (kept out of v0.1.0 to stay small).
- Chunked live SSE flushed while a queue worker is still running (queued mode currently waits for the worker, then emits SSE).
- Packagist listing (not published from this release).

## License

MIT. See [LICENSE.md](LICENSE.md).
