<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Ai;

use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Laravel\Server\EventLog;
use TiborSrc\A2A\Server\AgentExecutor;
use TiborSrc\A2A\Server\EventQueue;
use TiborSrc\A2A\Server\RequestContext;
use TiborSrc\A2A\Types\Artifact;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskArtifactUpdateEvent;
use TiborSrc\A2A\Types\TaskState;
use TiborSrc\A2A\Types\TaskStatus;
use TiborSrc\A2A\Types\TaskStatusUpdateEvent;

final readonly class LaravelAiExecutor implements AgentExecutor
{
    /**
     * @param  class-string<Agent>  $agentClass
     */
    public function __construct(
        private Container $container,
        private string $agentClass,
        private EventLog $log,
    ) {}

    public function execute(RequestContext $context, EventQueue $queue): void
    {
        $task = $context->task;

        if (! $task instanceof Task) {
            $queue->write(new Message(
                messageId: Id::uuid(),
                role: Role::Agent,
                parts: [Part::text($this->textFrom($context->message))],
            ));

            return;
        }

        $contextId = $task->contextId ?? $task->id;
        $taskId = $task->id;

        if ($this->log->canceled($taskId)) {
            $this->cancel($context, $queue);

            return;
        }

        $agent = $this->agent($contextId);
        $prompt = $this->textFrom($context->message);
        $attachments = $this->attachments($context->message);

        $queue->write(new TaskStatusUpdateEvent($taskId, $contextId, TaskStatus::now(TaskState::Working)));

        if ($context->streaming) {
            $text = '';

            foreach ($agent->stream($prompt, $attachments) as $event) {
                if ($this->log->canceled($taskId)) {
                    $this->cancel($context, $queue);

                    return;
                }

                if ($event instanceof TextDelta) {
                    $text .= $event->delta;
                    $queue->write(new TaskStatusUpdateEvent(
                        $taskId,
                        $contextId,
                        TaskStatus::now(TaskState::Working, $this->agentMessage($text, $contextId, $taskId)),
                    ));
                }
            }

            $this->complete($queue, $taskId, $contextId, $text, null);

            return;
        }

        $response = $agent->prompt($prompt, $attachments);
        $structured = null;

        if ($response instanceof StructuredAgentResponse) {
            $structured = [];

            foreach ($response->toArray() as $key => $value) {
                $structured[(string) $key] = $value;
            }
        }

        $this->complete($queue, $taskId, $contextId, $response->text, $structured);
    }

    public function cancel(RequestContext $context, EventQueue $queue): void
    {
        $task = $context->task;

        if (! $task instanceof Task) {
            return;
        }

        $queue->write(new TaskStatusUpdateEvent(
            $task->id,
            $task->contextId ?? $task->id,
            TaskStatus::now(TaskState::Canceled),
            final: true,
        ));
    }

    private function agent(?string $contextId): Agent
    {
        $agent = $this->container->make($this->agentClass);

        if (! $agent instanceof Agent) {
            throw new \RuntimeException("Configured A2A agent [{$this->agentClass}] must implement Laravel\\Ai\\Contracts\\Agent.");
        }

        if ($agent instanceof RemembersConversations && is_string($contextId) && $contextId !== '') {
            try {
                $agent->continue($contextId);
            } catch (\Throwable) {
                // A missing conversation starts a new turn; contextId still groups A2A tasks.
            }
        }

        return $agent;
    }

    /**
     * @return list<object>
     */
    private function attachments(Message $message): array
    {
        $files = [];

        foreach ($message->parts as $part) {
            if ($part->raw === null && $part->url === null) {
                continue;
            }

            $media = $part->mediaType ?? '';

            if ($part->raw !== null && str_starts_with($media, 'image/')) {
                $files[] = new Base64Image(base64_encode($part->raw), $media);
            }
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function complete(EventQueue $queue, string $taskId, string $contextId, string $text, ?array $data): void
    {
        if ($data !== null) {
            $queue->write(new TaskArtifactUpdateEvent(
                $taskId,
                $contextId,
                new Artifact(Id::uuid(), [new Part(data: $data)], 'structured'),
            ));
        }

        $parts = [];

        if ($text !== '') {
            $parts[] = Part::text($text);
        }

        if ($data !== null && $parts === []) {
            $parts[] = new Part(data: $data);
        }

        if ($parts === []) {
            $parts[] = Part::text('');
        }

        $queue->write(new TaskStatusUpdateEvent(
            $taskId,
            $contextId,
            TaskStatus::now(TaskState::Completed, new Message(
                messageId: Id::uuid(),
                role: Role::Agent,
                parts: $parts,
                contextId: $contextId,
                taskId: $taskId,
            )),
            final: true,
        ));
    }

    private function agentMessage(string $text, string $contextId, string $taskId): Message
    {
        return new Message(
            messageId: Id::uuid(),
            role: Role::Agent,
            parts: [Part::text($text)],
            contextId: $contextId,
            taskId: $taskId,
        );
    }

    private function textFrom(Message $message): string
    {
        $chunks = [];

        foreach ($message->parts as $part) {
            if ($part->text !== null) {
                $chunks[] = $part->text;
            }
        }

        return implode("\n", $chunks);
    }
}
