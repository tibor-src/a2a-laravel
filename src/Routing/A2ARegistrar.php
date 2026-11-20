<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Routing;

use Illuminate\Routing\Router;
use TiborSrc\A2A\Laravel\Http\A2AController;
use TiborSrc\A2A\Laravel\Support\Values;

final class A2ARegistrar
{
    public static function register(Router $router): void
    {
        $router->macro('a2a', function (?string $prefix = null, array $middleware = []) use ($router): void {
            /** @var array<int|string, mixed> $middleware */
            $prefix ??= Values::string(config('a2a.path'), '');
            $configured = config('a2a.middleware', []);
            $configured = is_array($configured) ? $configured : [];

            $middleware = $middleware === [] ? $configured : $middleware;

            $router->group([
                'prefix' => $prefix,
                'middleware' => $middleware,
            ], function (Router $router): void {
                $action = [A2AController::class, 'handle'];
                $router->get('/.well-known/agent-card.json', $action)->name('a2a.card');
                $router->post('/', $action)->name('a2a.jsonrpc');
                $router->post('/rpc', $action)->name('a2a.rpc');
                $router->post('/message:send', $action)->name('a2a.message.send');
                $router->post('/message:stream', $action)->name('a2a.message.stream');
                $router->get('/extendedAgentCard', $action)->name('a2a.extended-card');
                $router->get('/tasks', $action)->name('a2a.tasks.index');
                $router->post('/tasks/{task}:cancel', $action)->name('a2a.tasks.cancel');
                $router->match(['GET', 'POST'], '/tasks/{task}:subscribe', $action)->name('a2a.tasks.subscribe');
                $router->get('/tasks/{task}/pushNotificationConfigs/{config}', $action)->name('a2a.tasks.push.show');
                $router->delete('/tasks/{task}/pushNotificationConfigs/{config}', $action)->name('a2a.tasks.push.destroy');
                $router->match(['GET', 'POST'], '/tasks/{task}/pushNotificationConfigs', $action)->name('a2a.tasks.push');
                $router->get('/tasks/{task}', $action)->name('a2a.tasks.show');
            });
        });
    }
}
