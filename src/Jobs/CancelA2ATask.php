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
use TiborSrc\A2A\Types\Task;

final class CancelA2ATask implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|null  $task
     */
    public function __construct(
        public string $inner,
        public array $message,
        public ?array $task,
    ) {}

    public function handle(EventLog $log): void
    {
        $context = new RequestContext(
            message: Message::fromArray($this->message),
            task: $this->task === null ? null : Task::fromArray($this->task),
        );
        $taskId = $context->taskId() ?? $context->message->messageId;
        $queue = new EventQueue;
        $this->executor()->cancel($context, $queue);
        $log->append($taskId, $queue->events());
        $log->finish($taskId);
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
