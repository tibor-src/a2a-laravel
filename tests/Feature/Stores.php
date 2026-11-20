<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Server\CacheTaskStore;
use TiborSrc\A2A\Laravel\Server\EloquentTaskStore;
use TiborSrc\A2A\Laravel\Server\EventingTaskStore;
use TiborSrc\A2A\Types\ListTasksRequest;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;
use TiborSrc\A2A\Types\TaskState;
use TiborSrc\A2A\Types\TaskStatus;

function sampleTask(string $state = 'TASK_STATE_COMPLETED', ?string $context = 'ctx'): Task
{
    return new Task(
        id: Id::uuid(),
        status: TaskStatus::now(TaskState::from($state), new Message(Id::uuid(), Role::Agent, [Part::text('ok')])),
        contextId: $context,
        history: [new Message(Id::uuid(), Role::User, [Part::text('hi')])],
    );
}

it('persists tasks with the cache store', function (): void {
    config(['a2a.store' => 'cache']);
    $store = new CacheTaskStore(Cache::store());
    $task = sampleTask();
    $store->save($task);

    expect($store->find($task->id)?->id)->toBe($task->id)
        ->and($store->all())->toHaveCount(1)
        ->and($store->list(new ListTasksRequest(contextId: 'ctx')))->toHaveCount(1)
        ->and($store->list(new ListTasksRequest(contextId: 'other')))->toBeEmpty()
        ->and($store->list(new ListTasksRequest(status: TaskState::Failed)))->toBeEmpty()
        ->and($store->list(new ListTasksRequest(statusTimestampAfter: '1970-01-01T00:00:00.000Z')))->not->toBeEmpty();

    $config = new TaskPushNotificationConfig('https://example.com/h', id: 'p1', taskId: $task->id);
    $store->savePushConfig($config);
    expect($store->findPushConfig($task->id, 'p1')?->url)->toBe('https://example.com/h')
        ->and($store->listPushConfigs($task->id))->toHaveCount(1);

    $store->deletePushConfig($task->id, 'p1');
    expect($store->listPushConfigs($task->id))->toBeEmpty();

    $old = new Task(
        id: Id::uuid(),
        status: new TaskStatus(TaskState::Completed, timestamp: '2000-01-01T00:00:00.000Z'),
        contextId: 'old',
    );
    $store->save($old);
    expect($store->prune(new DateTimeImmutable('2010-01-01T00:00:00Z')))->toBe(1);
});

it('persists tasks with eloquent', function (): void {
    config(['a2a.store' => 'eloquent']);
    $store = new EloquentTaskStore;
    $task = sampleTask();
    $store->save($task);

    expect($store->find($task->id)?->id)->toBe($task->id)
        ->and($store->find('missing'))->toBeNull()
        ->and($store->all())->toHaveCount(1)
        ->and($store->list(new ListTasksRequest(contextId: 'ctx')))->toHaveCount(1)
        ->and($store->list(new ListTasksRequest(status: TaskState::Completed)))->toHaveCount(1)
        ->and($store->list(new ListTasksRequest(statusTimestampAfter: '1970-01-01T00:00:00.000Z')))->not->toBeEmpty()
        ->and($store->findPushConfig('missing', 'nope'))->toBeNull();

    $config = new TaskPushNotificationConfig('https://example.com/h', id: 'p1', taskId: $task->id);
    $store->savePushConfig($config);
    expect($store->findPushConfig($task->id, 'p1')?->url)->toBe('https://example.com/h')
        ->and($store->listPushConfigs($task->id))->toHaveCount(1);
    $store->deletePushConfig($task->id, 'p1');

    $old = new Task(
        id: Id::uuid(),
        status: new TaskStatus(TaskState::Canceled, timestamp: '2000-01-01T00:00:00.000Z'),
        contextId: 'old',
    );
    $store->save($old);
    expect($store->prune(new DateTimeImmutable('2010-01-01T00:00:00Z')))->toBe(1);
});

it('ignores cache index garbage and missing tasks', function (): void {
    Cache::put('a2a:tasks:index', 'nope');
    Cache::put('a2a:push:index', 'nope');
    $store = new CacheTaskStore(Cache::store());
    expect($store->find('missing'))->toBeNull()
        ->and($store->all())->toBeEmpty()
        ->and($store->findPushConfig('t', 'c'))->toBeNull()
        ->and($store->listPushConfigs('t'))->toBeEmpty()
        ->and($store->list(new ListTasksRequest(contextId: 'none')))->toBeEmpty();

    Cache::put('a2a:push:index', ['t1' => 'nope', 't2' => ['c1' => 'nope']]);
    expect($store->listPushConfigs('t1'))->toBeEmpty()
        ->and($store->listPushConfigs('t2'))->toBeEmpty();
});

it('wraps stores with lifecycle events', function (): void {
    $manager = $this->app->make(A2AManager::class);
    $store = $manager->store();
    expect($store)->toBeInstanceOf(EventingTaskStore::class)
        ->and($store->inner())->not->toBeNull();

    $task = sampleTask(TaskState::Working->value);
    $store->save($task);
    $store->save(new Task($task->id, TaskStatus::now(TaskState::Completed), $task->contextId));
    $store->save(new Task($task->id, TaskStatus::now(TaskState::Canceled), $task->contextId));
    expect($store->find($task->id)?->status->state)->toBe(TaskState::Canceled)
        ->and($store->list(new ListTasksRequest))->not->toBeEmpty()
        ->and($store->all())->not->toBeEmpty();
});

it('uses a file store when configured', function (): void {
    $path = sys_get_temp_dir().'/a2a-laravel-test-store.json';
    @unlink($path);
    config(['a2a.store' => 'file', 'a2a.file_store' => $path]);
    $store = $this->app->make(A2AManager::class)->store();
    $task = sampleTask();
    $store->save($task);
    expect($store->find($task->id)?->id)->toBe($task->id);
    @unlink($path);
});
