<?php

declare(strict_types=1);

use Laravel\Ai\Tools\Request;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Laravel\Ai\RemoteAgentTool;
use TiborSrc\A2A\Laravel\Facades\A2A;
use TiborSrc\A2A\Types\CancelTaskRequest;
use TiborSrc\A2A\Types\DeleteTaskPushNotificationConfigRequest;
use TiborSrc\A2A\Types\GetExtendedAgentCardRequest;
use TiborSrc\A2A\Types\GetTaskPushNotificationConfigRequest;
use TiborSrc\A2A\Types\GetTaskRequest;
use TiborSrc\A2A\Types\ListTaskPushNotificationConfigsRequest;
use TiborSrc\A2A\Types\ListTasksRequest;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\SendMessageRequest;
use TiborSrc\A2A\Types\SendMessageResponse;
use TiborSrc\A2A\Types\SubscribeToTaskRequest;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;
use TiborSrc\A2A\Types\TaskState;
use TiborSrc\A2A\Types\TaskStatus;

it('records faked client calls', function (): void {
    $fake = A2A::fake();
    $client = A2A::client('https://agent.example/');
    $request = new SendMessageRequest(new Message(Id::uuid(), Role::User, [Part::text('hello')]));
    $client->sendMessage($request);
    $client->sendStreamingMessage($request);

    $fake->assertSent('https://agent.example', fn (SendMessageRequest $sent): bool => $sent->message->parts[0]->text === 'hello');
    $fake->assertSentCount(2);

    expect($client->listTasks(new ListTasksRequest)->tasks)->toBeEmpty()
        ->and($client->subscribeToTask(new SubscribeToTaskRequest('t1')))->toBeEmpty()
        ->and($client->createTaskPushNotificationConfig(new TaskPushNotificationConfig('https://h.test'))->url)->toBe('https://h.test')
        ->and($client->getTaskPushNotificationConfig(new GetTaskPushNotificationConfigRequest('t1', 'c1'))->url)->toBe('https://agent.example')
        ->and($client->listTaskPushNotificationConfigs(new ListTaskPushNotificationConfigsRequest('t1'))->configs)->toBeEmpty()
        ->and($client->getExtendedAgentCard(new GetExtendedAgentCardRequest)->name)->toContain('Fake');

    $client->deleteTaskPushNotificationConfig(new DeleteTaskPushNotificationConfigRequest('t1', 'c1'));
    $task = $client->getTask(new GetTaskRequest('t1'));
    expect($client->cancelTask(new CancelTaskRequest($task->id))->id)->toBe($task->id);
});

it('asserts that nothing was sent', function (): void {
    A2A::fake()->assertNothingSent();
});

it('throws when a fake getTask has no task payload', function (): void {
    $fake = A2A::fake()->respondWithText('https://agent.example', 'only-message');
    $client = A2A::client('https://agent.example');

    expect(fn () => $client->getTask(new GetTaskRequest('t1')))->toThrow(RuntimeException::class)
        ->and($fake->recorded())->toBeEmpty();
});

it('streams a faked message response', function (): void {
    $fake = A2A::fake()->respondWithText('https://agent.example', 'pong');
    $events = A2A::client('https://agent.example')->sendStreamingMessage(
        new SendMessageRequest(new Message(Id::uuid(), Role::User, [Part::text('hello')])),
    );
    expect($events)->not->toBeEmpty();
    $fake->assertSent('https://agent.example');
});

it('reads remote tool text from a task status and empty parts', function (): void {
    config(['a2a.agents.remote' => 'https://agent.example']);
    A2A::fake()->respond('https://agent.example', new SendMessageResponse(
        task: new Task(Id::uuid(), TaskStatus::now(TaskState::Completed, new Message(Id::uuid(), Role::Agent, [Part::text('from-task')]))),
    ));
    $tool = A2A::tool('remote');
    expect($tool)->toBeInstanceOf(RemoteAgentTool::class)
        ->and($tool->handle(new Request(['task' => 'ping'])))->toBe('from-task');

    A2A::fake()->respond('https://agent.example', new SendMessageResponse(
        task: new Task(Id::uuid(), TaskStatus::now(TaskState::Completed)),
    ));
    expect(A2A::tool('remote')->handle(new Request(['task' => 'ping'])))->toBe(TaskState::Completed->value);

    A2A::fake()->respond('https://agent.example', new SendMessageResponse(
        message: new Message(Id::uuid(), Role::Agent, [new Part(data: ['ok' => true])]),
    ));
    expect(A2A::tool('remote')->handle(new Request(['task' => 1])))->toBeEmpty();
});

it('uses the fake client connect path', function (): void {
    $fake = A2A::fake();
    $client = A2A::client('https://other.example');
    $client->sendMessage(new SendMessageRequest(new Message(Id::uuid(), Role::User, [Part::text('x')])));

    $fake->assertSent('https://other.example');
});
