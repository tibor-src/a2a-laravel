<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue as QueueFacade;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Jobs\CancelA2ATask;
use TiborSrc\A2A\Laravel\Jobs\DeliverPushNotification;
use TiborSrc\A2A\Laravel\Jobs\ExecuteA2ATask;
use TiborSrc\A2A\Laravel\Server\EventLog;
use TiborSrc\A2A\Laravel\Server\QueuedAgentExecutor;
use TiborSrc\A2A\Server\EventQueue;
use TiborSrc\A2A\Server\RequestContext;
use TiborSrc\A2A\Server\TckExecutor;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;
use TiborSrc\A2A\Types\TaskState;
use TiborSrc\A2A\Types\TaskStatus;

it('dispatches queued execution and copies events back', function (): void {
    config(['a2a.queue.enabled' => true]);
    $manager = $this->app->make(A2AManager::class);
    expect($manager->executor())->toBeInstanceOf(QueuedAgentExecutor::class);

    $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-complete-queued',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'hello']],
        ],
    ])->assertOk()->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED');
});

it('cancels through the queued executor', function (): void {
    config(['a2a.queue.enabled' => true]);

    $created = $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-input-required-queued',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'wait']],
        ],
    ])->json();

    $this->jsonRpc('CancelTask', ['id' => $created['result']['task']['id']])
        ->assertOk()
        ->assertJsonPath('result.status.state', 'TASK_STATE_CANCELED');
});

it('times out when the event log never finishes', function (): void {
    $log = $this->app->make(EventLog::class);
    $log->start('missing');

    expect(fn () => $log->wait('missing', 0))->toThrow(RuntimeException::class)
        ->and($log->canceled('missing'))->toBeFalse();

    $log->start('done');
    $log->finish('done');
    $log->wait('done', 1);

    expect($log->finished('done'))->toBeTrue();
});

it('queues push notification delivery', function (): void {
    QueueFacade::fake();
    $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-complete-push',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'hello']],
        ],
        'configuration' => [
            'taskPushNotificationConfig' => [
                'url' => 'https://example.com/hook',
            ],
        ],
    ])->assertOk();

    QueueFacade::assertPushed(DeliverPushNotification::class);
});

it('runs execute and cancel jobs', function (): void {
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $message = new Message(Id::uuid(), Role::User, [Part::text('hi')], $task->contextId, $task->id);
    $log = $this->app->make(EventLog::class);
    $log->start($task->id);

    new ExecuteA2ATask(TckExecutor::class, $message->toArray(), $task->toArray(), null, null, false)
        ->handle($log);

    expect($log->finished($task->id))->toBeTrue()
        ->and($log->events($task->id))->not->toBeEmpty();

    $log->start($task->id);
    new CancelA2ATask(TckExecutor::class, $message->toArray(), $task->toArray())->handle($log);
    expect($log->finished($task->id))->toBeTrue();
});

it('skips execution when the task was canceled', function (): void {
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Working), Id::uuid());
    $message = new Message(Id::uuid(), Role::User, [Part::text('hi')], $task->contextId, $task->id);
    $log = $this->app->make(EventLog::class);
    $log->start($task->id);
    $log->cancel($task->id);

    new ExecuteA2ATask(TckExecutor::class, $message->toArray(), $task->toArray(), null, null, false)
        ->handle($log);

    expect($log->canceled($task->id))->toBeTrue();
});

it('rejects an invalid queued executor class', function (): void {
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $message = new Message(Id::uuid(), Role::User, [Part::text('hi')]);
    $log = $this->app->make(EventLog::class);
    $log->start($task->id);

    expect(fn () => new ExecuteA2ATask(stdClass::class, $message->toArray(), $task->toArray(), null, null, false)->handle($log))
        ->toThrow(RuntimeException::class)
        ->and(fn () => new CancelA2ATask(stdClass::class, $message->toArray(), $task->toArray())->handle($log))
        ->toThrow(RuntimeException::class);
});

it('copies events from a queued executor inner instance', function (): void {
    $executor = new QueuedAgentExecutor(
        new TckExecutor,
        $this->app->make(EventLog::class),
        $this->app->make(Dispatcher::class),
        'sync',
        'a2a',
        5,
    );
    expect($executor->inner())->toBeInstanceOf(TckExecutor::class);

    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message('tck-complete-direct', Role::User, [Part::text('hi')], $task->contextId, $task->id),
        $task,
    ), $queue);

    expect($queue->events())->not->toBeEmpty();
});

it('serializes stream responses for push jobs', function (): void {
    $config = new TaskPushNotificationConfig('https://example.test/h', id: 'c1', taskId: 't1');
    $payload = StreamResponse::fromEvent(new Task(Id::uuid(), TaskStatus::now(TaskState::Completed)));
    $job = new DeliverPushNotification($config->toArray(), $payload->toArray());
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([1, 5, 15]);
});
