<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Testing;

use PHPUnit\Framework\Assert;
use TiborSrc\A2A\Client\Client;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Protocol;
use TiborSrc\A2A\Types\AgentCapabilities;
use TiborSrc\A2A\Types\AgentCard;
use TiborSrc\A2A\Types\AgentInterface;
use TiborSrc\A2A\Types\AgentSkill;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\SendMessageRequest;
use TiborSrc\A2A\Types\SendMessageResponse;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskState;
use TiborSrc\A2A\Types\TaskStatus;

final class A2AFake
{
    /**
     * @var list<array{url: string, request: SendMessageRequest}>
     */
    private array $sent = [];

    /**
     * @var array<string, SendMessageResponse>
     */
    private array $responses = [];

    /**
     * @var array<string, AgentCard>
     */
    private array $cards = [];

    public function respond(string $url, SendMessageResponse $response): self
    {
        $this->responses[$this->normalize($url)] = $response;

        return $this;
    }

    public function respondWithText(string $url, string $text): self
    {
        return $this->respond($url, new SendMessageResponse(
            message: new Message(Id::uuid(), Role::Agent, [Part::text($text)]),
        ));
    }

    public function record(string $url, SendMessageRequest $request): void
    {
        $this->sent[] = ['url' => $this->normalize($url), 'request' => $request];
    }

    public function client(string $url): Client
    {
        return new FakeClient($this, $this->normalize($url));
    }

    public function card(string $url): AgentCard
    {
        $normalized = $this->normalize($url);

        return $this->cards[$normalized] ??= new AgentCard(
            name: 'Fake '.$normalized,
            description: 'Faked A2A agent.',
            supportedInterfaces: [
                new AgentInterface($normalized, Protocol::JSON_RPC_BINDING, Protocol::VERSION),
            ],
            version: '0.1.0',
            capabilities: new AgentCapabilities(streaming: true, pushNotifications: true),
            defaultInputModes: ['text/plain'],
            defaultOutputModes: ['text/plain'],
            skills: [new AgentSkill('fake', 'Fake', 'Fake skill', ['fake'])],
        );
    }

    public function withCard(string $url, AgentCard $card): self
    {
        $this->cards[$this->normalize($url)] = $card;

        return $this;
    }

    public function responseFor(string $url, SendMessageRequest $request): SendMessageResponse
    {
        $normalized = $this->normalize($url);

        if (isset($this->responses[$normalized])) {
            return $this->responses[$normalized];
        }

        $text = '';

        foreach ($request->message->parts as $part) {
            if ($part->text !== null) {
                $text = $part->text;
                break;
            }
        }

        return new SendMessageResponse(
            task: new Task(
                id: Id::uuid(),
                status: TaskStatus::now(TaskState::Completed, new Message(
                    messageId: Id::uuid(),
                    role: Role::Agent,
                    parts: [Part::text($text === '' ? 'faked' : $text)],
                )),
                contextId: $request->message->contextId ?? Id::uuid(),
            ),
        );
    }

    /**
     * @return list<array{url: string, request: SendMessageRequest}>
     */
    public function recorded(): array
    {
        return $this->sent;
    }

    public function assertSent(string $url, ?callable $callback = null): void
    {
        $normalized = $this->normalize($url);
        $matches = array_values(array_filter(
            $this->sent,
            fn (array $entry): bool => $entry['url'] === $normalized && ($callback === null || $callback($entry['request']) === true),
        ));

        Assert::assertNotSame([], $matches, "No A2A message was sent to [{$url}].");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, 'A2A messages were sent unexpectedly.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent);
    }

    private function normalize(string $url): string
    {
        return rtrim($url, '/');
    }
}
