<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use TiborSrc\A2A\Server\TckExecutor;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('a2a.executor', TckExecutor::class);
        $this->app['config']->set('a2a.store', getenv('A2A_STORE') ?: 'file');
        $this->app['config']->set('a2a.file_store', getenv('A2A_FILE_STORE') ?: sys_get_temp_dir().'/a2a-laravel-tck.json');
        $this->app['config']->set('a2a.base_url', getenv('A2A_BASE_URL') ?: 'http://127.0.0.1:9999');
        $this->app['config']->set('a2a.name', 'TCK Laravel Agent');
        $this->app['config']->set('a2a.description', 'A2A v1.0 Laravel workbench agent used for conformance testing.');
        $this->app['config']->set('a2a.queue.enabled', getenv('A2A_QUEUE') === '1');
        $this->app['config']->set('a2a.path', '');
        $this->app['config']->set('a2a.subscribe_timeout', 15);
        $this->app['config']->set('a2a.queue.timeout', 30);
        $this->app['config']->set('queue.default', 'sync');
        $cachePath = storage_path('framework/cache/data');

        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $this->app['config']->set('cache.default', 'file');
        $this->app['config']->set('cache.stores.file', [
            'driver' => 'file',
            'path' => $cachePath,
        ]);
    }

    public function boot(): void
    {
        $this->app['router']->a2a();
    }
}
