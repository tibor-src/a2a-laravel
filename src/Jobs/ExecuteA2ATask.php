<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TiborSrc\A2A\Laravel\Server\EventLog;
use TiborSrc\A2A\Server\AgentExecutor;
use TiborSrc\A2A\Server\EventQueue;
use TiborSrc\A2A\Server\RequestContext;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\SendMessageConfiguration;
use TiborSrc\A2A\Types\Task;

final class ExecuteA2ATask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|null  $task
     * @param  array<string, mixed>|null  $configuration
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $inner,
        public array $message,
        public ?array $task,
        public ?array $configuration,
        public ?array $metadata,
        public bool $streaming,
    ) {}

    public function handle(EventLog $log): void
    {
        $context = $this->context();
        $taskId = $context->taskId() ?? $context->message->messageId;
        $executor = $this->executor();
        $queue = new EventQueue;

        if ($log->canceled($taskId)) {
            $executor->cancel($context, $queue);
            $log->append($taskId, $queue->events());
            $log->finish($taskId);

            return;
        }

        $executor->execute($context, $queue);
        $log->append($taskId, $queue->events());
        $log->finish($taskId);
    }

    private function context(): RequestContext
    {
        return new RequestContext(
            message: Message::fromArray($this->message),
            task: $this->task === null ? null : Task::fromArray($this->task),
            configuration: $this->configuration === null ? null : SendMessageConfiguration::fromArray($this->configuration),
            metadata: $this->metadata,
            streaming: $this->streaming,
        );
    }

    private function executor(): AgentExecutor
    {
        $executor = app($this->inner);

        if (! $executor instanceof AgentExecutor) {
            throw new \RuntimeException("Invalid A2A executor [{$this->inner}].");
        }

        return $executor;
    }
}
