<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Tests\Doubles\EchoAgent;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Ai\AgentCardFactory;
use TiborSrc\A2A\Laravel\Ai\LaravelAiExecutor;
use TiborSrc\A2A\Laravel\Ai\RemoteAgentTool;
use TiborSrc\A2A\Laravel\Facades\A2A;
use TiborSrc\A2A\Laravel\Server\EventLog;
use TiborSrc\A2A\Server\EventQueue;
use TiborSrc\A2A\Server\RequestContext;
use TiborSrc\A2A\Types\AgentSkill;
use TiborSrc\A2A\Types\ListTasksRequest;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskState;
use TiborSrc\A2A\Types\TaskStatus;

it('builds an agent card from a laravel ai agent', function (): void {
    $factory = new AgentCardFactory($this->app['config']);
    $card = $factory->fromAgent(new EchoAgent);

    expect($card->name)->toBe('EchoAgent')
        ->and($card->description)->toBe('Echo the user.')
        ->and($card->skills)->not->toBeEmpty()
        ->and(array_map(fn (AgentSkill $skill): string => $skill->id, $card->skills))->toContain('extra', 'EchoTool');
});

it('executes a laravel ai agent over a2a', function (): void {
    config(['a2a.agent' => EchoAgent::class, 'a2a.executor' => null]);
    $this->app->instance(EchoAgent::class, new EchoAgent);

    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('hello')], $task->contextId, $task->id),
        $task,
    ), $queue);

    $events = $queue->events();
    expect($events)->not->toBeEmpty();
});

it('streams laravel ai text deltas', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('streamed')], $task->contextId, $task->id),
        $task,
        streaming: true,
    ), $queue);

    expect($queue->events())->not->toBeEmpty();
});

it('maps structured output with empty text to a data part', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('structured-empty')], $task->contextId, $task->id),
        $task,
    ), $queue);

    expect($queue->events())->not->toBeEmpty();
});

it('maps structured output to a data part artifact', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('structured')], $task->contextId, $task->id),
        $task,
    ), $queue);

    expect($queue->events())->not->toBeEmpty();
});

it('maps image parts to laravel ai attachments', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [
            Part::text('see'),
            new Part(raw: 'png-bytes', mediaType: 'image/png'),
            new Part(url: 'https://example.com/a.txt', mediaType: 'text/plain'),
        ], $task->contextId, $task->id),
        $task,
    ), $queue);

    expect($queue->events())->not->toBeEmpty();
});

it('cancels a streaming laravel ai task mid-stream', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    $log = $this->app->make(EventLog::class);
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    EchoAgent::$onStream = fn () => $log->cancel($task->id);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $log);
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('streamed')], $task->contextId, $task->id),
        $task,
        streaming: true,
    ), $queue);
    EchoAgent::$onStream = null;
    expect($queue->events())->not->toBeEmpty();
});

it('treats a missing conversation as a new turn', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), 'missing');
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('hello')], $task->contextId, $task->id),
        $task,
    ), $queue);
    expect($queue->events())->not->toBeEmpty();
});

it('rejects a configured class that is not a laravel ai agent', function (): void {
    $executor = new LaravelAiExecutor($this->app, stdClass::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $queue = new EventQueue;
    expect(fn () => $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('hello')], $task->contextId, $task->id),
        $task,
    ), $queue))->toThrow(RuntimeException::class);
});

it('cancels immediately when the event log is already canceled', function (): void {
    $log = $this->app->make(EventLog::class);
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Working), Id::uuid());
    $log->cancel($task->id);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $log);
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('hello')], $task->contextId, $task->id),
        $task,
    ), $queue);
    expect($queue->events())->not->toBeEmpty();
});

it('cancels a laravel ai task', function (): void {
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Working), Id::uuid());
    $queue = new EventQueue;
    $executor->cancel(new RequestContext(new Message(Id::uuid(), Role::User, [Part::text('x')]), $task), $queue);
    expect($queue->events())->not->toBeEmpty();
});

it('completes an empty prompt with an empty text part', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Submitted), Id::uuid());
    $queue = new EventQueue;
    $executor->execute(new RequestContext(
        new Message(Id::uuid(), Role::User, [Part::text('')], $task->contextId, $task->id),
        $task,
    ), $queue);
    expect($queue->events())->not->toBeEmpty();
});

it('returns a direct message when no task exists', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    $executor = new LaravelAiExecutor($this->app, EchoAgent::class, $this->app->make(EventLog::class));
    $queue = new EventQueue;
    $executor->execute(new RequestContext(new Message(Id::uuid(), Role::User, [Part::text('hi')])), $queue);
    expect($queue->events()[0])->toBeInstanceOf(Message::class);
    $executor->cancel(new RequestContext(new Message(Id::uuid(), Role::User, [Part::text('x')])), $queue);
});

it('exposes a remote a2a agent as a laravel ai tool', function (): void {
    config(['a2a.agents.remote' => 'https://agent.example']);
    $fake = A2A::fake()->respondWithText('https://agent.example', 'pong');
    $tool = A2A::tool('remote');

    expect($tool)->toBeInstanceOf(RemoteAgentTool::class)
        ->and($tool->name())->toBe('remote')
        ->and($tool->description())->toBe('Faked A2A agent.')
        ->and($tool->handle(new Request(['task' => 'ping'])))->toBe('pong');

    $schema = $tool->schema(new JsonSchemaTypeFactory);
    expect($schema)->toHaveKey('task');
    $fake->assertSent('https://agent.example');
});

it('lists and refreshes configured agent cards', function (): void {
    config(['a2a.agents.remote' => ['card' => 'https://agent.example']]);
    A2A::fake();
    $registry = $this->app->make(A2AManager::class)->registry();

    expect($registry->names())->toBe(['remote'])
        ->and($registry->refresh('remote')->name)->toContain('Fake')
        ->and($registry->client('remote')->listTasks(new ListTasksRequest)->totalSize)->toBe(0);
});
