<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use TiborSrc\A2A\Client\Client;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\SendMessageRequest;

final readonly class RemoteAgentTool implements Tool
{
    public function __construct(
        private string $name,
        private string $toolDescription,
        private Client $client,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The message to send to the remote A2A agent.'),
        ];
    }

    public function handle(Request $request): string
    {
        $task = $request['task'] ?? '';
        $response = $this->client->sendMessage(new SendMessageRequest(
            new Message(
                messageId: Id::uuid(),
                role: Role::User,
                parts: [Part::text(is_string($task) ? $task : '')],
            ),
        ));

        if ($response->message instanceof Message) {
            return $this->textFrom($response->message);
        }

        $statusMessage = $response->task?->status->message;

        if ($statusMessage instanceof Message) {
            return $this->textFrom($statusMessage);
        }

        return $response->task?->status->state->value ?? '';
    }

    private function textFrom(Message $message): string
    {
        foreach ($message->parts as $part) {
            if ($part->text !== null) {
                return $part->text;
            }
        }

        return '';
    }
}
