<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Server;

use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use TiborSrc\A2A\Server\TaskStore;
use TiborSrc\A2A\Types\Artifact;
use TiborSrc\A2A\Types\ListTasksRequest;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;
use TiborSrc\A2A\Types\TaskState;

final readonly class CacheTaskStore implements TaskStore
{
    private const string INDEX = 'a2a:tasks:index';

    private const string PUSH = 'a2a:push:index';

    public function __construct(
        private CacheRepository $cache,
        private int $ttl = 86400,
    ) {}

    public function save(Task $task): void
    {
        $payload = $task->toArray();
        $payload['history'] = array_map(fn (Message $message): array => $message->toArray(), $task->history);
        $payload['artifacts'] = array_map(fn (Artifact $artifact): array => $artifact->toArray(), $task->artifacts);
        $this->cache->put($this->taskKey($task->id), $payload, $this->ttl);
        $index = $this->ids();
        $index[$task->id] = $task->status->timestamp ?? '';
        $this->cache->put(self::INDEX, $index, $this->ttl);
    }

    public function find(string $id): ?Task
    {
        $payload = $this->cache->get($this->taskKey($id));

        if (! is_array($payload)) {
            return null;
        }

        return Task::fromArray($this->stringKeyed($payload));
    }

    public function all(): array
    {
        $tasks = [];

        foreach (array_keys($this->ids()) as $id) {
            $task = $this->find($id);

            if ($task instanceof Task) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    public function list(ListTasksRequest $request): array
    {
        return array_values(array_filter($this->all(), function (Task $task) use ($request): bool {
            if ($request->contextId !== null && $task->contextId !== $request->contextId) {
                return false;
            }

            if ($request->status instanceof TaskState && $task->status->state !== $request->status) {
                return false;
            }

            if ($request->statusTimestampAfter !== null) {
                return ($task->status->timestamp ?? '') >= $request->statusTimestampAfter;
            }

            return true;
        }));
    }

    public function savePushConfig(TaskPushNotificationConfig $config): void
    {
        $taskId = $config->taskId ?? '';
        $id = $config->id ?? '';
        $all = $this->pushIndex();
        $all[$taskId][$id] = $config->toArray();
        $this->cache->put(self::PUSH, $all, $this->ttl);
    }

    public function findPushConfig(string $taskId, string $id): ?TaskPushNotificationConfig
    {
        $payload = $this->pushIndex()[$taskId][$id] ?? null;

        if ($payload === null) {
            return null;
        }

        return TaskPushNotificationConfig::fromArray($payload);
    }

    public function listPushConfigs(string $taskId): array
    {
        $out = [];

        foreach ($this->pushIndex()[$taskId] ?? [] as $payload) {
            $out[] = TaskPushNotificationConfig::fromArray($payload);
        }

        return $out;
    }

    public function deletePushConfig(string $taskId, string $id): void
    {
        $all = $this->pushIndex();
        unset($all[$taskId][$id]);
        $this->cache->put(self::PUSH, $all, $this->ttl);
    }

    public function prune(DateTimeInterface $before): int
    {
        $cutoff = $before->format('Y-m-d\TH:i:s.v\Z');
        $removed = 0;
        $index = $this->ids();

        foreach ($this->all() as $task) {
            $timestamp = $task->status->timestamp ?? '';

            if ($task->status->state->isTerminal() && $timestamp !== '' && $timestamp < $cutoff) {
                $this->cache->forget($this->taskKey($task->id));
                unset($index[$task->id]);
                $removed++;
            }
        }

        $this->cache->put(self::INDEX, $index, $this->ttl);

        return $removed;
    }

    /**
     * @return array<string, string>
     */
    private function ids(): array
    {
        $index = $this->cache->get(self::INDEX, []);

        if (! is_array($index)) {
            return [];
        }

        $out = [];

        foreach ($index as $id => $timestamp) {
            $out[(string) $id] = is_scalar($timestamp) ? (string) $timestamp : '';
        }

        return $out;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function pushIndex(): array
    {
        $index = $this->cache->get(self::PUSH, []);

        if (! is_array($index)) {
            return [];
        }

        $out = [];

        foreach ($index as $taskId => $bucket) {
            if (! is_array($bucket)) {
                continue;
            }

            $configs = [];

            foreach ($bucket as $id => $payload) {
                if (is_array($payload)) {
                    $configs[(string) $id] = $this->stringKeyed($payload);
                }
            }

            $out[(string) $taskId] = $configs;
        }

        return $out;
    }

    private function taskKey(string $id): string
    {
        return 'a2a:task:'.$id;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $out = [];

        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }
}
