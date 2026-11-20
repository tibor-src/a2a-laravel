<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Server;

use Illuminate\Contracts\Events\Dispatcher;
use TiborSrc\A2A\Laravel\Events\TaskCanceled;
use TiborSrc\A2A\Laravel\Events\TaskCompleted;
use TiborSrc\A2A\Laravel\Events\TaskCreated;
use TiborSrc\A2A\Laravel\Events\TaskUpdated;
use TiborSrc\A2A\Server\TaskStore;
use TiborSrc\A2A\Types\ListTasksRequest;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;
use TiborSrc\A2A\Types\TaskState;

final readonly class EventingTaskStore implements TaskStore
{
    public function __construct(
        private TaskStore $inner,
        private Dispatcher $events,
    ) {}

    public function save(Task $task): void
    {
        $previous = $this->inner->find($task->id);
        $this->inner->save($task);

        if (! $previous instanceof Task) {
            $this->events->dispatch(new TaskCreated($task));

            return;
        }

        $this->events->dispatch(new TaskUpdated($task, $previous));

        if ($task->status->state === TaskState::Completed) {
            $this->events->dispatch(new TaskCompleted($task));
        }

        if ($task->status->state === TaskState::Canceled) {
            $this->events->dispatch(new TaskCanceled($task));
        }
    }

    public function find(string $id): ?Task
    {
        return $this->inner->find($id);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function list(ListTasksRequest $request): array
    {
        return $this->inner->list($request);
    }

    public function savePushConfig(TaskPushNotificationConfig $config): void
    {
        $this->inner->savePushConfig($config);
    }

    public function findPushConfig(string $taskId, string $id): ?TaskPushNotificationConfig
    {
        return $this->inner->findPushConfig($taskId, $id);
    }

    public function listPushConfigs(string $taskId): array
    {
        return $this->inner->listPushConfigs($taskId);
    }

    public function deletePushConfig(string $taskId, string $id): void
    {
        $this->inner->deletePushConfig($taskId, $id);
    }

    public function inner(): TaskStore
    {
        return $this->inner;
    }
}
