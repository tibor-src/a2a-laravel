<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Server;

use Illuminate\Contracts\Bus\Dispatcher;
use TiborSrc\A2A\Laravel\Jobs\CancelA2ATask;
use TiborSrc\A2A\Laravel\Jobs\ExecuteA2ATask;
use TiborSrc\A2A\Server\AgentExecutor;
use TiborSrc\A2A\Server\EventQueue;
use TiborSrc\A2A\Server\RequestContext;
use TiborSrc\A2A\Types\SendMessageConfiguration;
use TiborSrc\A2A\Types\Task;

final readonly class QueuedAgentExecutor implements AgentExecutor
{
    public function __construct(
        private AgentExecutor $inner,
        private EventLog $log,
        private Dispatcher $bus,
        private ?string $connection = null,
        private string $queue = 'a2a',
        private int $timeout = 30,
    ) {}

    public function execute(RequestContext $context, EventQueue $queue): void
    {
        $taskId = $this->taskId($context);
        $this->log->start($taskId);
        $this->dispatch(new ExecuteA2ATask(
            inner: $this->inner::class,
            message: $context->message->toArray(),
            task: $context->task instanceof Task ? $context->task->toArray() : null,
            configuration: $context->configuration instanceof SendMessageConfiguration ? $context->configuration->toArray() : null,
            metadata: $context->metadata,
            streaming: $context->streaming,
        ));
        $this->log->wait($taskId, $this->timeout);
        $this->copy($taskId, $queue);
    }

    public function cancel(RequestContext $context, EventQueue $queue): void
    {
        $taskId = $this->taskId($context);
        $this->log->cancel($taskId);
        $this->dispatch(new CancelA2ATask(
            inner: $this->inner::class,
            message: $context->message->toArray(),
            task: $context->task instanceof Task ? $context->task->toArray() : null,
        ));
        $this->log->wait($taskId, $this->timeout);
        $this->copy($taskId, $queue);
    }

    public function inner(): AgentExecutor
    {
        return $this->inner;
    }

    private function dispatch(object $job): void
    {
        if ($job instanceof ExecuteA2ATask || $job instanceof CancelA2ATask) {
            if ($this->connection !== null && $this->connection !== '') {
                $job->onConnection($this->connection);
            }

            $job->onQueue($this->queue);
        }

        $this->bus->dispatch($job);
    }

    private function copy(string $taskId, EventQueue $queue): void
    {
        foreach ($this->log->events($taskId) as $event) {
            $queue->write($event);
        }
    }

    private function taskId(RequestContext $context): string
    {
        return $context->taskId() ?? $context->message->messageId;
    }
}
