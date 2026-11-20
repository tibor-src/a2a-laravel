<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Agent;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use TiborSrc\A2A\Client\Auth;
use TiborSrc\A2A\Client\Client;
use TiborSrc\A2A\Client\ClientFactory;
use TiborSrc\A2A\Laravel\Ai\AgentCardFactory;
use TiborSrc\A2A\Laravel\Ai\AgentRegistry;
use TiborSrc\A2A\Laravel\Ai\LaravelAiExecutor;
use TiborSrc\A2A\Laravel\Ai\RemoteAgentTool;
use TiborSrc\A2A\Laravel\Server\CacheTaskStore;
use TiborSrc\A2A\Laravel\Server\EloquentTaskStore;
use TiborSrc\A2A\Laravel\Server\EventingTaskStore;
use TiborSrc\A2A\Laravel\Server\EventLog;
use TiborSrc\A2A\Laravel\Server\NotifyingPushSender;
use TiborSrc\A2A\Laravel\Server\QueuedAgentExecutor;
use TiborSrc\A2A\Laravel\Support\Values;
use TiborSrc\A2A\Laravel\Testing\A2AFake;
use TiborSrc\A2A\Server\AgentExecutor;
use TiborSrc\A2A\Server\Application;
use TiborSrc\A2A\Server\FileTaskStore;
use TiborSrc\A2A\Server\InMemoryTaskStore;
use TiborSrc\A2A\Server\PushNotificationSender;
use TiborSrc\A2A\Server\RequestHandler;
use TiborSrc\A2A\Server\TaskStore;
use TiborSrc\A2A\Server\TckExecutor;
use TiborSrc\A2A\Types\AgentCard;

final class A2AManager
{
    private ?A2AFake $fake = null;

    private ?TaskStore $store = null;

    public function __construct(
        private readonly Container $container,
    ) {}

    public function application(): Application
    {
        $factory = new Psr17Factory;

        return new Application($this->handler(), $factory, $factory);
    }

    public function handler(): RequestHandler
    {
        $card = $this->card();

        return new RequestHandler(
            agentCard: $card,
            executor: $this->executor(),
            store: $this->store(),
            pushSender: $this->pushSender(),
            extendedAgentCard: $card,
            subscribeTimeoutSeconds: Values::integer($this->config()->get('a2a.subscribe_timeout', 15), 15),
        );
    }

    public function store(): TaskStore
    {
        if ($this->store instanceof TaskStore) {
            return $this->store;
        }

        $config = $this->config();
        $driver = Values::string($config->get('a2a.store', 'memory'), 'memory');

        $inner = match ($driver) {
            'eloquent' => new EloquentTaskStore,
            'cache' => new CacheTaskStore($this->container->make(CacheRepository::class)),
            'file' => new FileTaskStore(Values::string($config->get('a2a.file_store'), sys_get_temp_dir().'/a2a-laravel-tasks.json')),
            default => new InMemoryTaskStore,
        };

        return $this->store = new EventingTaskStore($inner, $this->container->make(Dispatcher::class));
    }

    public function executor(): AgentExecutor
    {
        $inner = $this->innerExecutor();

        if ($this->config()->get('a2a.queue.enabled') === true) {
            return new QueuedAgentExecutor(
                $inner,
                $this->container->make(EventLog::class),
                $this->container->make(\Illuminate\Contracts\Bus\Dispatcher::class),
                Values::string($this->config()->get('a2a.queue.connection')),
                Values::string($this->config()->get('a2a.queue.name', 'a2a'), 'a2a'),
                Values::integer($this->config()->get('a2a.queue.timeout', 30), 30),
            );
        }

        return $inner;
    }

    public function card(?string $baseUrl = null): AgentCard
    {
        $factory = new AgentCardFactory($this->config());
        $agentClass = $this->config()->get('a2a.agent');

        if (is_string($agentClass) && $agentClass !== '' && is_a($agentClass, Agent::class, true)) {
            $agent = $this->container->make($agentClass);

            if ($agent instanceof Agent) {
                return $factory->fromAgent($agent, $baseUrl);
            }
        }

        return $factory->fromConfig($baseUrl);
    }

    public function client(string $agentUrl, ?Auth $auth = null, ?string $binding = null): Client
    {
        if ($this->fake instanceof A2AFake) {
            return $this->fake->client($agentUrl);
        }

        return $this->factory()->connect($agentUrl, $auth, $binding);
    }

    public function tool(string $name, ?string $description = null): RemoteAgentTool
    {
        return $this->registry()->tool($name, $description);
    }

    public function registry(): AgentRegistry
    {
        return new AgentRegistry(
            $this->config(),
            $this->container->make(CacheRepository::class),
            $this->factory(),
            $this->fake,
            $this->http(),
        );
    }

    public function fake(): A2AFake
    {
        return $this->fake ??= new A2AFake;
    }

    public function factory(): ClientFactory
    {
        return new ClientFactory($this->http());
    }

    public function eventLog(): EventLog
    {
        return $this->container->make(EventLog::class);
    }

    private function innerExecutor(): AgentExecutor
    {
        $executor = $this->config()->get('a2a.executor');

        if (is_string($executor) && $executor !== '') {
            if ($executor === TckExecutor::class || is_a($executor, AgentExecutor::class, true)) {
                $resolved = $this->container->make($executor);

                if ($resolved instanceof AgentExecutor) {
                    return $resolved;
                }
            }
        }

        $agentClass = $this->config()->get('a2a.agent');

        if (is_string($agentClass) && $agentClass !== '' && is_a($agentClass, Agent::class, true)) {
            return new LaravelAiExecutor($this->container, $agentClass, $this->container->make(EventLog::class));
        }

        return new TckExecutor;
    }

    private function pushSender(): PushNotificationSender
    {
        return new NotifyingPushSender(
            $this->container->make(Dispatcher::class),
            $this->container->make(\Illuminate\Contracts\Bus\Dispatcher::class),
            Values::string($this->config()->get('a2a.queue.name', 'a2a'), 'a2a'),
        );
    }

    private function http(): ClientInterface
    {
        return $this->container->make(ClientInterface::class);
    }

    private function config(): ConfigRepository
    {
        return $this->container->make(ConfigRepository::class);
    }
}
