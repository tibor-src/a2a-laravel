<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use TiborSrc\A2A\Client\Auth;
use TiborSrc\A2A\Client\Client;
use TiborSrc\A2A\Laravel\A2AManager;
use TiborSrc\A2A\Laravel\Ai\AgentRegistry;
use TiborSrc\A2A\Laravel\Ai\RemoteAgentTool;
use TiborSrc\A2A\Laravel\Testing\A2AFake;
use TiborSrc\A2A\Server\Application;
use TiborSrc\A2A\Server\RequestHandler;
use TiborSrc\A2A\Server\TaskStore;
use TiborSrc\A2A\Types\AgentCard;

/**
 * @method static Application application()
 * @method static RequestHandler handler()
 * @method static TaskStore store()
 * @method static AgentCard card(?string $baseUrl = null)
 * @method static Client client(string $agentUrl, ?Auth $auth = null, ?string $binding = null)
 * @method static RemoteAgentTool tool(string $name, ?string $description = null)
 * @method static AgentRegistry registry()
 * @method static A2AFake fake()
 */
final class A2A extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return A2AManager::class;
    }
}
