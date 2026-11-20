<?php

declare(strict_types=1);

namespace TiborSrc\A2A\Laravel\Ai;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Http\Client\ClientInterface;
use TiborSrc\A2A\Client\AgentCardResolver;
use TiborSrc\A2A\Client\Client;
use TiborSrc\A2A\Client\ClientFactory;
use TiborSrc\A2A\Laravel\Support\Values;
use TiborSrc\A2A\Laravel\Testing\A2AFake;
use TiborSrc\A2A\Types\AgentCard;

final readonly class AgentRegistry
{
    public function __construct(
        private ConfigRepository $config,
        private CacheRepository $cache,
        private ClientFactory $factory,
        private ?A2AFake $fake = null,
        private ?ClientInterface $http = null,
    ) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $agents = $this->config->get('a2a.agents', []);

        if (! is_array($agents)) {
            return [];
        }

        return array_map(strval(...), array_keys($agents));
    }

    public function url(string $name): string
    {
        $url = $this->config->get("a2a.agents.{$name}.card") ?? $this->config->get("a2a.agents.{$name}");

        if (! is_string($url) || $url === '') {
            throw new \InvalidArgumentException("Unknown A2A agent [{$name}].");
        }

        return $url;
    }

    public function card(string $name, bool $refresh = false): AgentCard
    {
        $url = $this->url($name);
        $key = 'a2a:cards:'.$name;

        if ($refresh) {
            $this->cache->forget($key);
        }

        if ($this->fake instanceof A2AFake) {
            return $this->fake->card($url);
        }

        $ttl = Values::integer($this->config->get('a2a.card_cache_ttl', 3600), 3600);
        $cached = $this->cache->remember($key, $ttl, fn (): array => $this->resolve($url)->toArray());

        if ($cached === []) {
            return $this->resolve($url);
        }

        return AgentCard::fromArray($cached);
    }

    public function client(string $name): Client
    {
        if ($this->fake instanceof A2AFake) {
            return $this->fake->client($this->url($name));
        }

        return $this->factory->forCard($this->card($name));
    }

    public function tool(string $name, ?string $description = null): RemoteAgentTool
    {
        $card = $this->card($name);

        return new RemoteAgentTool(
            $name,
            $description ?? $card->description,
            $this->client($name),
        );
    }

    public function refresh(string $name): AgentCard
    {
        return $this->card($name, true);
    }

    private function resolve(string $url): AgentCard
    {
        return new AgentCardResolver(
            $this->http ?? Psr18ClientDiscovery::find(),
            Psr17FactoryDiscovery::findRequestFactory(),
        )->resolve($url);
    }
}
