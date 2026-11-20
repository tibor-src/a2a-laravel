<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Server;

use DateTimeInterface;
use TiborSrc\A2A\Laravel\Server\Models\A2APushConfig;
use TiborSrc\A2A\Laravel\Server\Models\A2ATask;
use TiborSrc\A2A\Server\TaskStore;
use TiborSrc\A2A\Types\Artifact;
use TiborSrc\A2A\Types\ListTasksRequest;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;
use TiborSrc\A2A\Types\TaskState;

final class EloquentTaskStore implements TaskStore
{
    public function save(Task $task): void
    {
        $payload = $task->toArray();
        $payload['history'] = array_map(fn (Message $message): array => $message->toArray(), $task->history);
        $payload['artifacts'] = array_map(fn (Artifact $artifact): array => $artifact->toArray(), $task->artifacts);

        A2ATask::query()->updateOrCreate(
            ['id' => $task->id],
            [
                'context_id' => $task->contextId,
                'state' => $task->status->state->value,
                'status_timestamp' => $task->status->timestamp,
                'payload' => $payload,
            ],
        );
    }

    public function find(string $id): ?Task
    {
        $row = A2ATask::query()->find($id);

        if (! $row instanceof A2ATask) {
            return null;
        }

        return Task::fromArray($row->payloadArray());
    }

    public function all(): array
    {
        return array_values(A2ATask::query()->get()->map(fn (A2ATask $row): Task => Task::fromArray($row->payloadArray()))->all());
    }

    public function list(ListTasksRequest $request): array
    {
        $query = A2ATask::query();

        if ($request->contextId !== null) {
            $query->where('context_id', $request->contextId);
        }

        if ($request->status instanceof TaskState) {
            $query->where('state', $request->status->value);
        }

        if ($request->statusTimestampAfter !== null) {
            $query->where('status_timestamp', '>=', $request->statusTimestampAfter);
        }

        return array_values($query->get()->map(fn (A2ATask $row): Task => Task::fromArray($row->payloadArray()))->all());
    }

    public function savePushConfig(TaskPushNotificationConfig $config): void
    {
        A2APushConfig::query()->updateOrCreate(
            [
                'id' => $config->id ?? '',
                'task_id' => $config->taskId ?? '',
            ],
            ['payload' => $config->toArray()],
        );
    }

    public function findPushConfig(string $taskId, string $id): ?TaskPushNotificationConfig
    {
        $row = A2APushConfig::query()->where('task_id', $taskId)->where('id', $id)->first();

        if (! $row instanceof A2APushConfig) {
            return null;
        }

        return TaskPushNotificationConfig::fromArray($row->payloadArray());
    }

    public function listPushConfigs(string $taskId): array
    {
        return array_values(A2APushConfig::query()
            ->where('task_id', $taskId)
            ->get()
            ->map(fn (A2APushConfig $row): TaskPushNotificationConfig => TaskPushNotificationConfig::fromArray($row->payloadArray()))
            ->all());
    }

    public function deletePushConfig(string $taskId, string $id): void
    {
        A2APushConfig::query()->where('task_id', $taskId)->where('id', $id)->delete();
    }

    public function prune(DateTimeInterface $before): int
    {
        $cutoff = $before->format('Y-m-d\TH:i:s.v\Z');
        $terminal = [
            TaskState::Completed->value,
            TaskState::Failed->value,
            TaskState::Canceled->value,
            TaskState::Rejected->value,
        ];

        $ids = A2ATask::query()
            ->whereIn('state', $terminal)
            ->where('status_timestamp', '<', $cutoff)
            ->pluck('id');

        A2APushConfig::query()->whereIn('task_id', $ids)->delete();

        $deleted = A2ATask::query()->whereIn('id', $ids)->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
