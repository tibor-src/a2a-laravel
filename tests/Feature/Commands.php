<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Facades\A2A;
use TiborSrc\A2A\Laravel\Notifications\A2AWebhookChannel;
use TiborSrc\A2A\Laravel\Notifications\TaskPushNotification;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;
use TiborSrc\A2A\Types\TaskState;
use TiborSrc\A2A\Types\TaskStatus;

it('lists and fetches configured cards', function (): void {
    config(['a2a.agents.remote' => 'https://agent.example']);
    A2A::fake();

    $this->artisan('a2a:cards')->assertSuccessful();
    $this->artisan('a2a:cards:fetch')->assertSuccessful();
    $this->artisan('a2a:cards:fetch', ['name' => 'remote'])->assertSuccessful();
});

it('warns when no agents are configured', function (): void {
    config(['a2a.agents' => []]);
    $this->artisan('a2a:cards')->assertSuccessful();
    $this->artisan('a2a:cards:fetch')->assertSuccessful();
});

it('prunes cache and eloquent stores', function (): void {
    config(['a2a.store' => 'cache']);
    $this->app->forgetInstance(A2AManager::class);
    $this->artisan('a2a:prune', ['--days' => 1])->assertSuccessful();

    config(['a2a.store' => 'eloquent']);
    $this->app->forgetInstance(A2AManager::class);
    $this->artisan('a2a:prune')->assertSuccessful();

    config(['a2a.store' => 'memory']);
    $this->app->forgetInstance(A2AManager::class);
    $this->artisan('a2a:prune')->assertSuccessful();
});

it('prints the tck command on a dry run', function (): void {
    $this->artisan('a2a:tck', ['--dry-run' => true])->assertSuccessful();
    $this->artisan('a2a:tck', ['--queued' => true, '--dry-run' => true])->assertSuccessful();
});

it('runs the tck command with a stand-in binary', function (): void {
    putenv('A2A_TCK_PHP=true');
    $this->artisan('a2a:tck')->assertSuccessful();
    putenv('A2A_TCK_PHP');
});

it('generates an executor class', function (): void {
    $path = $this->app->basePath('app/A2A/DemoExecutor.php');
    File::delete($path);
    $this->artisan('make:a2a-executor', ['name' => 'DemoExecutor'])->assertSuccessful();
    expect(File::exists($path))->toBeTrue();
    File::delete($path);
});

it('builds a webhook notification', function (): void {
    $config = new TaskPushNotificationConfig('https://example.com/h');
    $payload = StreamResponse::fromEvent(new Task('t1', TaskStatus::now(TaskState::Completed)));
    $notification = new TaskPushNotification($config, $payload);

    expect($notification->via(new stdClass))->toBe([A2AWebhookChannel::class])
        ->and($notification->toWebhook(new stdClass))->toHaveKey('task');
});
