<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use TiborSrc\A2A\Laravel\A2AServiceProvider;
use TiborSrc\A2A\Protocol;
use TiborSrc\A2A\Server\TckExecutor;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [A2AServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('a2a.store', 'memory');
        $app['config']->set('a2a.queue.enabled', false);
        $app['config']->set('a2a.executor', TckExecutor::class);
        $app['config']->set('a2a.agent', null);
        $app['config']->set('a2a.base_url', 'http://127.0.0.1:9999');
        $app['config']->set('a2a.path', '');
        $app['config']->set('a2a.auth.enabled', false);
        $app['config']->set('a2a.subscribe_timeout', 0);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->a2a();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function jsonRpc(string $method, array $params = [], mixed $id = 1): TestResponse
    {
        return $this->call('POST', '/', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
        ], json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR));
    }
}
