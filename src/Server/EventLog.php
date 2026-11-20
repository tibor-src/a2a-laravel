<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Server;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskArtifactUpdateEvent;
use TiborSrc\A2A\Types\TaskStatusUpdateEvent;

final readonly class EventLog
{
    public function __construct(
        private CacheRepository $cache,
        private int $ttl = 3600,
    ) {}

    public function start(string $taskId): void
    {
        $this->cache->put($this->key($taskId), [
            'events' => [],
            'done' => false,
            'canceled' => false,
        ], $this->ttl);
    }

    /**
     * @param  list<Task|Message|TaskStatusUpdateEvent|TaskArtifactUpdateEvent>  $events
     */
    public function append(string $taskId, array $events): void
    {
        $state = $this->state($taskId);
        $encoded = $state['events'];

        foreach ($events as $event) {
            $encoded[] = StreamResponse::fromEvent($event)->toArray();
        }

        $state['events'] = $encoded;
        $this->cache->put($this->key($taskId), $state, $this->ttl);
    }

    public function finish(string $taskId): void
    {
        $state = $this->state($taskId);
        $state['done'] = true;
        $this->cache->put($this->key($taskId), $state, $this->ttl);
    }

    public function cancel(string $taskId): void
    {
        $state = $this->state($taskId);
        $state['canceled'] = true;
        $this->cache->put($this->key($taskId), $state, $this->ttl);
    }

    /**
     * @phpstan-impure
     */
    public function canceled(string $taskId): bool
    {
        return $this->state($taskId)['canceled'] === true;
    }

    public function finished(string $taskId): bool
    {
        return $this->state($taskId)['done'] === true;
    }

    /**
     * @return list<Task|Message|TaskStatusUpdateEvent|TaskArtifactUpdateEvent>
     */
    public function events(string $taskId): array
    {
        $out = [];

        foreach ($this->state($taskId)['events'] as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            /** @var array<string, mixed> $payload */
            $stream = StreamResponse::fromArray($payload);

            if ($stream->task instanceof Task) {
                $out[] = $stream->task;
            }

            if ($stream->message instanceof Message) {
                $out[] = $stream->message;
            }

            if ($stream->statusUpdate instanceof TaskStatusUpdateEvent) {
                $out[] = $stream->statusUpdate;
            }

            if ($stream->artifactUpdate instanceof TaskArtifactUpdateEvent) {
                $out[] = $stream->artifactUpdate;
            }
        }

        return $out;
    }

    public function wait(string $taskId, int $seconds): void
    {
        $deadline = time() + $seconds;

        while (time() <= $deadline) {
            if ($this->finished($taskId)) {
                return;
            }

            usleep(50_000);
        }

        throw new \RuntimeException("Timed out waiting for A2A task {$taskId}.");
    }

    /**
     * @return array{events: list<mixed>, done: bool, canceled: bool}
     */
    private function state(string $taskId): array
    {
        $state = $this->cache->get($this->key($taskId));

        if (! is_array($state)) {
            return ['events' => [], 'done' => false, 'canceled' => false];
        }

        $events = $state['events'] ?? [];

        return [
            'events' => is_array($events) ? array_values($events) : [],
            'done' => $state['done'] === true,
            'canceled' => $state['canceled'] === true,
        ];
    }

    private function key(string $taskId): string
    {
        return 'a2a:events:'.$taskId;
    }
}
