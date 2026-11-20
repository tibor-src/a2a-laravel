<?php

declare(strict_types=1);

namespace Tests\Doubles;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\HasSkills;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Skills\Skill;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;

final class EchoAgent implements Agent, HasSkills, HasStructuredOutput, HasTools, RemembersConversations
{
    public static ?\Closure $onStream = null;

    private ?string $conversationId = null;

    public function instructions(): string
    {
        return 'Echo the user.';
    }

    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        unset($attachments, $provider, $model, $timeout);
        $text = is_string($prompt) ? $prompt : 'echo';

        if ($text === 'structured') {
            return new StructuredAgentResponse('inv', ['ok' => true], $text, new TextUsage, new Meta);
        }

        if ($text === 'structured-empty') {
            return new StructuredAgentResponse('inv', ['ok' => true], '', new TextUsage, new Meta);
        }

        return new AgentResponse('inv', $text, new TextUsage, new Meta);
    }

    public function stream(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse {
        unset($attachments, $provider, $model, $timeout);
        $text = is_string($prompt) ? $prompt : 'echo';

        $onStream = self::$onStream;

        return new StreamableAgentResponse('inv', function () use ($text, $onStream) {
            yield new TextDelta('d1', 'm1', $text, time());

            if ($onStream instanceof \Closure) {
                $onStream();
            }

            yield new StreamEnd('e1', 'stop', new TextUsage, time());
        }, new Meta);
    }

    public function queue(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        unset($prompt, $attachments, $provider, $model);

        throw new \RuntimeException('Queue is not used in tests.');
    }

    public function broadcast(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        unset($channels, $attachments, $now, $provider, $model);

        return $this->stream($prompt);
    }

    public function broadcastNow(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): StreamableAgentResponse {
        return $this->broadcast($prompt, $channels, $attachments, true, $provider, $model);
    }

    public function broadcastOnQueue(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): QueuedAgentResponse {
        unset($prompt, $channels, $attachments, $provider, $model);

        throw new \RuntimeException('Broadcast queue is not used in tests.');
    }

    public function tools(): iterable
    {
        return [new EchoTool, 'skip'];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['ok' => $schema->boolean()];
    }

    public function skills(): iterable
    {
        return [new Skill('extra', 'An extra skill.', 'Do extra.'), 'skip'];
    }

    public function messages(): iterable
    {
        return [];
    }

    public function forParticipant(object $participant): static
    {
        unset($participant);

        return $this;
    }

    public function forUser(object $user): static
    {
        return $this->forParticipant($user);
    }

    public function continue(string $conversationId, ?object $as = null): static
    {
        unset($as);

        if ($conversationId === 'missing') {
            throw new \RuntimeException('gone');
        }

        $this->conversationId = $conversationId;

        return $this;
    }

    public function continueOrStart(?string $conversationId, object $as): static
    {
        unset($as);
        $this->conversationId = $conversationId;

        return $this;
    }

    public function continueLastConversation(object $as): static
    {
        unset($as);

        return $this;
    }

    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    public function hasConversationParticipant(): bool
    {
        return false;
    }

    public function conversationParticipant(): ?object
    {
        return null;
    }
}

final class EchoTool implements Tool
{
    public function description(): string
    {
        return 'Echoes a task.';
    }

    public function handle(Request $request): string
    {
        $task = $request['task'] ?? '';

        return is_string($task) ? $task : '';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['task' => $schema->string()];
    }
}
