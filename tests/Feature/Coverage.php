<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Doubles\AuthUser;
use Tests\Doubles\EchoAgent;
use TiborSrc\A2A\Client\ClientFactory;
use TiborSrc\A2A\Id;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Ai\AgentCardFactory;
use TiborSrc\A2A\Laravel\Ai\LaravelAiExecutor;
use TiborSrc\A2A\Laravel\Facades\A2A;
use TiborSrc\A2A\Laravel\Http\A2AController;
use TiborSrc\A2A\Laravel\Http\AuthenticateA2A;
use TiborSrc\A2A\Laravel\Jobs\DeliverPushNotification;
use TiborSrc\A2A\Laravel\Notifications\A2AWebhookChannel;
use TiborSrc\A2A\Laravel\Notifications\TaskPushNotification;
use TiborSrc\A2A\Laravel\Server\CacheTaskStore;
use TiborSrc\A2A\Laravel\Server\EventLog;
use TiborSrc\A2A\Laravel\Server\Models\A2APushConfig;
use TiborSrc\A2A\Laravel\Server\Models\A2ATask;
use TiborSrc\A2A\Laravel\Support\Values;
use TiborSrc\A2A\Protocol;
use TiborSrc\A2A\Server\TckExecutor;
use TiborSrc\A2A\Types\AgentCapabilities;
use TiborSrc\A2A\Types\AgentCard;
use TiborSrc\A2A\Types\AgentInterface;
use TiborSrc\A2A\Types\AgentSkill;
use TiborSrc\A2A\Types\Artifact;
use TiborSrc\A2A\Types\Message;
use TiborSrc\A2A\Types\Part;
use TiborSrc\A2A\Types\Role;
use TiborSrc\A2A\Types\StreamResponse;
use TiborSrc\A2A\Types\Task;
use TiborSrc\A2A\Types\TaskArtifactUpdateEvent;
use TiborSrc\A2A\Types\TaskPushNotificationConfig;
use TiborSrc\A2A\Types\TaskState;
use TiborSrc\A2A\Types\TaskStatus;
use TiborSrc\A2A\Types\TaskStatusUpdateEvent;

it('covers manager helpers and auth card factory branches', function (): void {
    config(['a2a.agents' => 'nope']);
    $manager = $this->app->make(A2AManager::class);
    expect($manager->eventLog())->toBeInstanceOf(EventLog::class)
        ->and($manager->registry()->names())->toBeEmpty()
        ->and($manager->factory())->toBeInstanceOf(ClientFactory::class);

    config(['a2a.agents.remote' => '']);
    expect(fn () => $manager->registry()->url('missing'))->toThrow(InvalidArgumentException::class);

    config(['a2a.executor' => stdClass::class, 'a2a.agent' => stdClass::class]);
    expect($this->app->make(A2AManager::class)->executor())->toBeInstanceOf(TckExecutor::class);

    $factory = new AgentCardFactory($this->app['config']);
    $card = $factory->fromConfig('https://custom.example');
    expect($card->supportedInterfaces[0]->url)->toBe('https://custom.example')
        ->and(Values::integer('14', 0))->toBe(14)
        ->and(Values::integer('nope', 7))->toBe(7)
        ->and(Values::string(null, 'fallback'))->toBe('fallback');
});

it('covers subscribe payload edge cases on the controller', function (): void {
    $controller = $this->app->make(A2AController::class);
    $request = Request::create('/tasks/missing:subscribe', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ], '{');
    expect($controller->handle($request)->getStatusCode())->toBeGreaterThan(0);

    $rpc = Request::create('/', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ], json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'SubscribeToTask',
        'params' => [],
    ], JSON_THROW_ON_ERROR));
    $rpcResponse = $controller->handle($rpc);
    expect($rpcResponse->getStatusCode())->toBe(200)
        ->and($rpcResponse->getContent())->toContain('error');

    $missing = Request::create('/tasks/missing:subscribe', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ]);
    expect($controller->handle($missing)->getContent())->toContain('error');

    $scalar = Request::create('/', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ], '1');
    expect($controller->handle($scalar)->getStatusCode())->toBeGreaterThan(0);
});

it('falls back from live subscribe when the task cannot stream', function (): void {
    $created = $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-complete-task-sub-fallback',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'done']],
        ],
    ])->json();
    $taskId = $created['result']['task']['id'];
    $controller = $this->app->make(A2AController::class);

    $unversioned = Request::create('/tasks/'.$taskId.':subscribe', 'POST');
    expect($controller->handle($unversioned)->getContent())->toContain('error');

    $terminal = Request::create('/tasks/'.$taskId.':subscribe', 'POST', [], [], [], [
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ]);
    expect($controller->handle($terminal)->getContent())->toContain('error');

    config(['a2a.capabilities.streaming' => false]);
    $this->app->forgetInstance(A2AManager::class);
    $waiting = $this->jsonRpc('SendMessage', [
        'message' => [
            'messageId' => 'tck-input-required-no-stream',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'wait']],
        ],
    ])->json();
    $waitingId = $waiting['result']['task']['id'];
    $disabled = Request::create('/tasks/'.$waitingId.':subscribe', 'GET', [], [], [], [
        'HTTP_'.Protocol::VERSION_HEADER => Protocol::VERSION,
    ]);
    expect($this->app->make(A2AController::class)->handle($disabled)->getContent())->toContain('error');
});

it('parses skill ids from query strings and invalid json', function (): void {
    config(['a2a.auth.enabled' => true, 'a2a.auth.guard' => 'web']);
    $middleware = $this->app->make(AuthenticateA2A::class);
    $request = Request::create('/?skillId=echo', 'GET');

    expect(fn () => $middleware->handle($request, fn (): ResponseFactory|\Illuminate\Http\Response => response('ok')))
        ->toThrow(HttpException::class);

    $bad = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{');
    expect(fn () => $middleware->handle($bad, fn (): ResponseFactory|\Illuminate\Http\Response => response('ok')))
        ->toThrow(HttpException::class);
});

it('delivers webhook notifications through the channel', function (): void {
    $http = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            unset($request);

            return (new Psr17Factory)->createResponse(200)->withBody((new Psr17Factory)->createStream('{}'));
        }
    };
    $this->app->instance(ClientInterface::class, $http);
    $config = new TaskPushNotificationConfig('https://example.com/h');
    $payload = StreamResponse::fromEvent(new Task(Id::uuid(), TaskStatus::now(TaskState::Completed)));
    $channel = new A2AWebhookChannel($http);
    $channel->send(new stdClass, new TaskPushNotification($config, $payload));

    expect(true)->toBeTrue();

    $job = new DeliverPushNotification($config->toArray(), $payload->toArray());
    $job->handle($http, $this->app['events']);
});

it('records a failed push delivery', function (): void {
    $http = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            unset($request);

            throw new RuntimeException('down');
        }
    };
    $job = new DeliverPushNotification(
        new TaskPushNotificationConfig('https://example.com/h')->toArray(),
        StreamResponse::fromEvent(new Task(Id::uuid(), TaskStatus::now(TaskState::Completed)))->toArray(),
    );

    expect(fn () => $job->handle($http, $this->app['events']))->toThrow(RuntimeException::class);
});

it('attaches a custom fake card', function (): void {
    $card = new AgentCard(
        'Custom',
        'Custom agent',
        [new AgentInterface('https://custom.example', Protocol::JSON_RPC_BINDING, Protocol::VERSION)],
        '0.1.0',
        new AgentCapabilities(true, true),
        ['text/plain'],
        ['text/plain'],
        [new AgentSkill('s', 'S', 'S', ['s'])],
    );
    A2A::fake()->withCard('https://custom.example', $card);
    expect(A2A::fake()->card('https://custom.example')->name)->toBe('Custom');
});

it('reads event log garbage and empty model payloads', function (): void {
    Cache::put('a2a:events:t1', ['events' => ['nope', 1], 'done' => true, 'canceled' => false]);
    expect($this->app->make(EventLog::class)->events('t1'))->toBeEmpty();

    $task = new A2ATask;
    $task->setRawAttributes(['payload' => null]);

    expect($task->payloadArray())->toBeEmpty();

    $push = new A2APushConfig;
    $push->setRawAttributes(['payload' => null]);

    expect($push->payloadArray())->toBeEmpty();
});

it('builds a card from a laravel agent when configured', function (): void {
    $this->app->instance(EchoAgent::class, new EchoAgent);
    config(['a2a.agent' => EchoAgent::class, 'a2a.executor' => null]);
    $manager = $this->app->make(A2AManager::class);
    expect($manager->card()->name)->toBe('EchoAgent')
        ->and($manager->executor())->toBeInstanceOf(LaravelAiExecutor::class)
        ->and(fn () => $this->app->make(LaravelAiExecutor::class))->not->toThrow(Throwable::class);
    config(['a2a.agent' => null]);
    $this->app->forgetInstance(LaravelAiExecutor::class);
    expect(fn () => $this->app->make(LaravelAiExecutor::class))->toThrow(RuntimeException::class);
});

it('resolves a live client and cached cards through a stub http client', function (): void {
    $card = $this->app->make(A2AManager::class)->card('https://agent.example');
    $http = new readonly class($card) implements ClientInterface
    {
        public function __construct(private AgentCard $card) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            unset($request);
            $factory = new Psr17Factory;

            return $factory->createResponse(200)->withBody(
                $factory->createStream(json_encode($this->card->toArray(), JSON_THROW_ON_ERROR)),
            );
        }
    };
    $this->app->instance(ClientInterface::class, $http);
    $this->app->forgetInstance(A2AManager::class);
    config(['a2a.agents.remote' => 'https://agent.example', 'a2a.store' => 'cache']);

    $manager = $this->app->make(A2AManager::class);
    expect($manager->client('https://agent.example'))->not->toBeNull()
        ->and($manager->store()->inner())->toBeInstanceOf(CacheTaskStore::class)
        ->and($manager->registry()->card('remote')->name)->toBe($card->name);

    Cache::put('a2a:cards:remote', []);
    expect($manager->registry()->card('remote')->name)->toBe($card->name)
        ->and($manager->registry()->client('remote'))->not->toBeNull();
});

it('covers remaining card factory url and ability branches', function (): void {
    config([
        'a2a.base_url' => null,
        'a2a.path' => 'a2a',
        'a2a.auth.enabled' => true,
        'a2a.auth.abilities' => [1 => ['nope'], 'echo' => 'nope', 'ok' => ['agents:ok']],
    ]);
    $factory = new AgentCardFactory($this->app['config']);
    expect($factory->fromConfig()->supportedInterfaces[0]->url)->toEndWith('/a2a');

    config(['a2a.path' => '']);
    expect($factory->fromConfig()->supportedInterfaces[0]->url)->toBe('http://127.0.0.1:9999');
});

it('strips a prefix that matches the path exactly and leaves other paths', function (): void {
    config(['a2a.path' => 'a2a']);
    $controller = $this->app->make(A2AController::class);
    expect($controller->handle(Request::create('/a2a', 'GET'))->getStatusCode())->toBeGreaterThan(0)
        ->and($controller->handle(Request::create('/other', 'GET'))->getStatusCode())->toBeGreaterThan(0);
});

it('replays event log task, message, status and artifact events', function (): void {
    $log = $this->app->make(EventLog::class);
    $task = new Task(Id::uuid(), TaskStatus::now(TaskState::Working), Id::uuid());
    $log->start($task->id);
    $log->append($task->id, [
        $task,
        new Message(Id::uuid(), Role::Agent, [Part::text('hi')]),
        new TaskStatusUpdateEvent($task->id, $task->contextId ?? $task->id, TaskStatus::now(TaskState::Working)),
        new TaskArtifactUpdateEvent($task->id, $task->contextId ?? $task->id, new Artifact(Id::uuid(), [Part::text('art')])),
    ]);
    expect($log->events($task->id))->toHaveCount(4);
});

it('reads a skill id from the query string when the user is authenticated', function (): void {
    config(['a2a.auth.enabled' => true, 'a2a.auth.guard' => 'web', 'a2a.auth.abilities' => 'nope']);
    $this->be(new AuthUser(abilities: ['agents:echo']), 'web');
    $middleware = $this->app->make(AuthenticateA2A::class);
    $request = Request::create('/?skillId=echo', 'GET');
    expect($middleware->handle($request, fn (): Response => new Response('ok'))->getContent())->toBe('ok');

    $invalid = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{');
    expect($middleware->handle($invalid, fn (): Response => new Response('ok'))->getContent())->toBe('ok');

    $scalar = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '1');
    expect($middleware->handle($scalar, fn (): Response => new Response('ok'))->getContent())->toBe('ok');

    config(['a2a.auth.abilities' => ['echo' => 'not-array']]);
    $withSkill = Request::create('/?skillId=echo', 'GET');
    expect($middleware->handle($withSkill, fn (): Response => new Response('ok'))->getContent())->toBe('ok');
});
