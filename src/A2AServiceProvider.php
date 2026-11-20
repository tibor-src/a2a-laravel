<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel;

use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application as LaravelApplication;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\Agent;
use Psr\Http\Client\ClientInterface;
use TiborSrc\A2A\Laravel\Ai\LaravelAiExecutor;
use TiborSrc\A2A\Laravel\Console\FetchCardsCommand;
use TiborSrc\A2A\Laravel\Console\ListCardsCommand;
use TiborSrc\A2A\Laravel\Console\MakeExecutorCommand;
use TiborSrc\A2A\Laravel\Console\PruneTasksCommand;
use TiborSrc\A2A\Laravel\Console\TckCommand;
use TiborSrc\A2A\Laravel\Routing\A2ARegistrar;
use TiborSrc\A2A\Laravel\Server\EventLog;
use TiborSrc\A2A\Server\Application;
use TiborSrc\A2A\Server\RequestHandler;
use TiborSrc\A2A\Server\TaskStore;

final class A2AServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/a2a.php', 'a2a');

        $this->app->singleton(A2AManager::class, fn (LaravelApplication $app): A2AManager => new A2AManager($app));
        $this->app->alias(A2AManager::class, 'a2a');
        $this->app->singleton(EventLog::class, fn (LaravelApplication $app): EventLog => new EventLog($app->make(CacheRepository::class)));
        $this->app->bind(ClientInterface::class, fn (): ClientInterface => Psr18ClientDiscovery::find());
        $this->app->bind(LaravelAiExecutor::class, function (LaravelApplication $app): LaravelAiExecutor {
            $agent = $app->make(ConfigRepository::class)->get('a2a.agent');

            if (! is_string($agent) || $agent === '' || ! is_a($agent, Agent::class, true)) {
                throw new \RuntimeException('Set a2a.agent to a Laravel AI agent class.');
            }

            return new LaravelAiExecutor($app, $agent, $app->make(EventLog::class));
        });
        $this->app->bind(TaskStore::class, fn (LaravelApplication $app): TaskStore => $app->make(A2AManager::class)->store());
        $this->app->bind(RequestHandler::class, fn (LaravelApplication $app): RequestHandler => $app->make(A2AManager::class)->handler());
        $this->app->bind(Application::class, fn (LaravelApplication $app): Application => $app->make(A2AManager::class)->application());
    }

    public function boot(): void
    {
        A2ARegistrar::register($this->app->make(Router::class));

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/a2a.php' => config_path('a2a.php'),
            ], 'a2a-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'a2a-migrations');

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            $this->commands([
                MakeExecutorCommand::class,
                FetchCardsCommand::class,
                ListCardsCommand::class,
                PruneTasksCommand::class,
                TckCommand::class,
            ]);
        }
    }
}
