<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Events\TaskCompleted;
use TiborSrc\A2A\Laravel\Events\TaskCreated;
use TiborSrc\A2A\Laravel\Facades\A2A;
use TiborSrc\A2A\Laravel\Http\A2AController;
use TiborSrc\A2A\Protocol;
use TiborSrc\A2A\Server\TckExecutor;

it('auto-discovers the service provider and facade', function (): void {
    expect($this->app->make(A2AManager::class))->toBeInstanceOf(A2AManager::class)
        ->and(A2A::card()->name)->toBe('Laravel A2A Agent');
});

it('serves the agent card at the well-known path', function (): void {
    $this->get('/.well-known/agent-card.json')
        ->assertOk()
        ->assertHeader('Content-Type', Protocol::HTTP_JSON_CONTENT_TYPE)
        ->assertJsonPath('name', 'Laravel A2A Agent')
        ->assertJsonPath('capabilities.streaming', true);
});

it('sends a message over json-rpc and rest', function (): void {
    Event::fake([TaskCreated::class, TaskCompleted::class]);

    $rpc = $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-complete-task-1',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'hello']],
        ],
    ])->assertOk()->json();

    expect($rpc['result']['task']['status']['state'])->toBe('TASK_STATE_COMPLETED');

    $rest = $this->call('POST', '/message:send', [], [], [], [
        'CONTENT_TYPE' => Protocol::HTTP_JSON_CONTENT_TYPE,
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ], json_encode([
        'message' => [
            'messageId' => 'tck-complete-task-2',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'hello']],
        ],
    ], JSON_THROW_ON_ERROR))->assertOk()->json();

    expect($rest['task']['status']['state'])->toBe('TASK_STATE_COMPLETED');
    Event::assertDispatched(TaskCreated::class);
});

it('streams a message over json-rpc', function (): void {
    $response = $this->jsonRpc('SendStreamingMessage', [
        'message' => [
            'messageId' => 'tck-stream-hello',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'hello']],
        ],
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain(Protocol::SSE_CONTENT_TYPE)
        ->and($response->getContent())->toContain('TASK_STATE_COMPLETED');
});

it('lists, gets and cancels tasks', function (): void {
    $created = $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-input-required-1',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'wait']],
        ],
    ])->json();
    $taskId = $created['result']['task']['id'];

    $this->jsonRpc('GetTask', ['id' => $taskId])
        ->assertOk()
        ->assertJsonPath('result.id', $taskId);

    $this->jsonRpc('ListTasks', [])
        ->assertOk()
        ->assertJsonPath('result.totalSize', 1);

    $this->jsonRpc('CancelTask', ['id' => $taskId])
        ->assertOk()
        ->assertJsonPath('result.status.state', 'TASK_STATE_CANCELED');
});

it('manages push notification configs', function (): void {
    $created = $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-input-required-push',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'wait']],
        ],
    ])->json();
    $taskId = $created['result']['task']['id'];

    $config = $this->jsonRpc('CreateTaskPushNotificationConfig', [
        'taskId' => $taskId,
        'url' => 'https://example.com/hook',
    ])->assertOk()->json();

    $configId = $config['result']['id'];

    $this->jsonRpc('GetTaskPushNotificationConfig', ['taskId' => $taskId, 'id' => $configId])->assertOk();
    $this->jsonRpc('ListTaskPushNotificationConfigs', ['taskId' => $taskId])->assertOk();
    $this->jsonRpc('DeleteTaskPushNotificationConfig', ['taskId' => $taskId, 'id' => $configId])->assertOk();
});

it('returns the extended agent card', function (): void {
    $this->jsonRpc('GetExtendedAgentCard', [])
        ->assertOk()
        ->assertJsonPath('result.name', 'Laravel A2A Agent');

    $this->call('GET', '/extendedAgentCard', [], [], [], [
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ])->assertOk();
});

it('strips a route prefix before handing the request to a2a-php', function (): void {
    $this->app['router']->a2a('agents');
    $this->get('/agents/.well-known/agent-card.json')
        ->assertOk()
        ->assertJsonPath('name', 'Laravel A2A Agent');
});

it('streams subscribe responses', function (): void {
    $created = $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-input-required-sub',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'wait']],
        ],
    ])->json();
    $taskId = $created['result']['task']['id'];

    $subscribe = $this->call('POST', '/tasks/'.$taskId.':subscribe', [], [], [], [
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ])->assertOk();
    expect($subscribe->headers->get('Content-Type'))->toContain(Protocol::SSE_CONTENT_TYPE)
        ->and($subscribe->streamedContent())->toContain('TASK_STATE_INPUT_REQUIRED');

    $rpc = $this->jsonRpc('SubscribeToTask', ['id' => $taskId])->assertOk();
    expect($rpc->headers->get('Content-Type'))->toContain(Protocol::SSE_CONTENT_TYPE)
        ->and($rpc->streamedContent())->toContain('jsonrpc');

    $byTaskId = $this->jsonRpc('SubscribeToTask', ['task_id' => $taskId])->assertOk();
    expect($byTaskId->streamedContent())->toContain('TASK_STATE_INPUT_REQUIRED');

    $this->jsonRpc('SubscribeToTask', ['id' => 'missing-task'])
        ->assertOk()
        ->assertJsonPath('error.code', -32001);
});

it('uses the configured tck executor by default', function (): void {
    expect(A2A::application())->not->toBeNull()
        ->and(config('a2a.executor'))->toBe(TckExecutor::class);
});

it('converts illuminate requests through the controller', function (): void {
    $controller = $this->app->make(A2AController::class);
    $request = Request::create('/.well-known/agent-card.json', 'GET');
    $response = $controller->handle($request);

    expect($response->getStatusCode())->toBe(200);
});
